<?php

use App\Models\GeneralScheduling;
use App\Models\Scheduling;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La programación se desglosa por día: lo que se registra "del 03/11 al
     * 30/11, Lun–Vie" queda como una programación general, y cada día que
     * cubre es una programación propia (schedulings) con su código, que se
     * modifica, finaliza o elimina por separado.
     *
     * Las programaciones que ya existían se convierten aquí: cada una pasa a
     * ser una general y se parte en un día por cada recorrido que tenía, más
     * los días que le quedaban por delante.
     */
    public function up(): void
    {
        Schema::create('general_schedulings', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->foreignId('zone_id')->constrained('zones')->cascadeOnDelete();
            $table->foreignId('staffgroup_id')->nullable()->constrained('staffgroups')->nullOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignId('schedule_id')->constrained('schedules')->restrictOnDelete();
            $table->json('weekdays')->comment('Dias ISO-8601: 1 lunes a 7 domingo');
            $table->json('worked_holidays')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['start_date', 'end_date']);
        });

        Schema::table('schedulings', function (Blueprint $table) {
            $table->foreignId('general_scheduling_id')->nullable()->after('id')->constrained('general_schedulings')->cascadeOnDelete();
        });

        $this->splitExisting();
    }

    public function down(): void
    {
        // Los días ya partidos se quedan como programaciones de un día.
        Schema::table('schedulings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('general_scheduling_id');
        });

        Schema::dropIfExists('general_schedulings');
    }

    private function splitExisting(): void
    {
        $today = now()->toDateString();

        foreach (Scheduling::with('helpers')->orderBy('id')->get() as $scheduling) {
            $general = GeneralScheduling::create([
                ...$scheduling->only(['zone_id', 'staffgroup_id', 'vehicle_id', 'schedule_id', 'weekdays', 'worked_holidays', 'notes']),
                'start_date' => $scheduling->start_date->toDateString(),
                'end_date' => $scheduling->end_date->toDateString(),
            ]);

            $inForce = in_array($scheduling->status, Scheduling::IN_FORCE, true);
            $routeDates = DB::table('vehicleroutes')->where('scheduling_id', $scheduling->id)->pluck('date_route')
                ->map(fn ($date) => substr((string) $date, 0, 10));
            // Lo que ya pasó sin recorrido nunca ocurrió: no se inventa.
            $upcoming = $inForce
                ? array_filter($scheduling->occurrenceDates(), fn (string $date) => $date >= $today)
                : [];
            $dates = $routeDates->concat($upcoming)->unique()->sort()->values()->all()
                ?: [$scheduling->start_date->toDateString()];

            $history = DB::table('scheduling_changes')->where('scheduling_id', $scheduling->id)->get();

            foreach ($dates as $index => $date) {
                $status = $inForce && $date < $today ? 'finalizada' : $scheduling->status;
                $day = [
                    'general_scheduling_id' => $general->id,
                    'start_date' => $date,
                    'end_date' => $date,
                    'weekdays' => json_encode([(int) date('N', strtotime($date))]),
                    'worked_holidays' => json_encode(in_array($date, $scheduling->worked_holidays ?? [], true) ? [$date] : []),
                    'status' => $status,
                ];

                // El primer día se queda con la fila original (y su código).
                if ($index === 0) {
                    DB::table('schedulings')->where('id', $scheduling->id)->update($day);

                    continue;
                }

                $id = DB::table('schedulings')->insertGetId([
                    ...collect($scheduling->getAttributes())->except(['id', 'code', 'created_at', 'updated_at'])->all(),
                    ...$day,
                    'code' => Scheduling::nextCode(),
                    'created_at' => $scheduling->created_at,
                    'updated_at' => now(),
                ]);

                foreach ($scheduling->helpers as $helper) {
                    DB::table('schedulinghelpers')->insert([
                        'scheduling_id' => $id,
                        'user_id' => $helper->id,
                        'position' => $helper->pivot->position,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // Los cambios se hicieron sobre todo el rango: cada día los conserva.
                foreach ($history as $change) {
                    DB::table('scheduling_changes')->insert([
                        ...collect((array) $change)->except('id')->all(),
                        'scheduling_id' => $id,
                    ]);
                }

                DB::table('vehicleroutes')
                    ->where('scheduling_id', $scheduling->id)
                    ->whereDate('date_route', $date)
                    ->update(['scheduling_id' => $id]);
            }
        }
    }
};
