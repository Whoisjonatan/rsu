<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;

/**
 * Marcaciones de las ultimas semanas, para que el reporte W12 tenga con que
 * trabajar. Se generan de forma determinista (sin azar) para que dos corridas
 * den lo mismo y los numeros del reporte sean reproducibles.
 */
class AttendanceSeeder extends Seeder
{
    /** Dias hacia atras que se siembran. */
    private const DAYS_BACK = 30;

    public function run(): void
    {
        $schedules = Schedule::orderBy('time_start')->get();

        if ($schedules->isEmpty()) {
            return;
        }

        $today = CarbonImmutable::now()->startOfDay();
        $from = $today->subDays(self::DAYS_BACK);

        $people = User::with('contracts')
            ->whereNotNull('dni')
            ->where('dni', '!=', '10000001')
            ->orderBy('id')
            ->get();

        foreach ($people as $index => $person) {
            // Cada persona queda anclada a un turno, rotando entre los que haya.
            $schedule = $schedules[$index % $schedules->count()];
            $start = $schedule->time_start;

            foreach (CarbonPeriod::create($from, $today->subDay()) as $day) {
                $date = CarbonImmutable::parse($day);

                // Domingo no se trabaja.
                if ($date->dayOfWeekIso === 7) {
                    continue;
                }

                $dateString = $date->toDateString();

                if (! $person->hasActiveContractOn($dateString) || $person->isOnVacationOn($dateString)) {
                    continue;
                }

                // Patron fijo: 1 de cada 9 falta y 1 de cada 5 llega tarde.
                $seed = $person->id * 31 + $date->dayOfYear;

                if ($seed % 9 === 0) {
                    continue; // sin marcacion: el reporte lo mostrara como ausente
                }

                $late = $seed % 5 === 0;
                [$hour, $minute] = array_map('intval', explode(':', (string) $start));
                $checkIn = $date->setTime($hour, $minute)
                    ->addMinutes($late ? 25 + ($seed % 20) : -12 + ($seed % 15));

                Attendance::updateOrCreate(
                    ['user_id' => $person->id, 'date' => $dateString],
                    [
                        'schedule_id' => $schedule->id,
                        'check_in' => $checkIn->format('H:i:s'),
                        'status' => $late ? 'tarde' : 'presente',
                    ],
                );
            }
        }
    }
}
