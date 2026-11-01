<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Support\Status;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Marcación de asistencia (W11) y su reporte (W12). */
class AttendanceController extends Controller
{
    /** Filas por página en la tabla del reporte (W12). */
    private const PER_PAGE = 9;

    public function __construct(private readonly AttendanceService $attendances) {}

    /**
     * W11 Terminal de marcación. Es un equipo compartido, sin navegación
     * lateral y sin sesión iniciada: el trabajador se identifica con su DNI
     * y su clave en el propio formulario.
     */
    public function terminal(): View
    {
        return view('attendances.terminal', [
            'windows' => Schedule::markingWindows(CarbonImmutable::now()),
        ]);
    }

    public function mark(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'dni' => ['required', 'digits:8'],
            'password' => ['required', 'string'],
            // Cada botón del terminal manda el suyo: entrada o salida del turno.
            'type' => ['required', 'in:entrada,salida'],
        ], [
            'dni.digits' => 'El DNI debe tener 8 dígitos.',
        ]);

        $result = $this->attendances->mark($data['dni'], $data['password'], $data['type']);

        // Nunca se devuelve el DNI ni la clave al formulario: el siguiente
        // trabajador no debe encontrarlos escritos.
        return redirect()->route('attendances.terminal')->with('marking', $result);
    }

    /** W12 Reporte de asistencias, por persona o por fecha. */
    public function report(Request $request): View|StreamedResponse
    {
        $tab = $request->query('tab') === 'fecha' ? 'fecha' : 'persona';

        $filters = [
            'user_id' => $request->query('user_id'),
            'from' => $request->query('from', now()->startOfMonth()->toDateString()),
            'to' => $request->query('to', now()->toDateString()),
            'date' => $request->query('date', now()->toDateString()),
            'status' => $request->query('status'),
        ];

        $validator = Validator::make($filters, [
            'user_id' => ['nullable', 'exists:users,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'date' => ['required', 'date'],
            'status' => ['nullable', 'in:presente,tarde,falta'],
        ]);

        $report = null;

        if (! $validator->fails()) {
            if ($tab === 'fecha') {
                $report = $this->attendances->byDate($filters['date'], $filters['status']);
            } elseif ($filters['user_id']) {
                $report = $this->attendances->byPerson(
                    (int) $filters['user_id'], $filters['from'], $filters['to'], $filters['status'],
                );
            }
        }

        if ($report && $request->query('export') === 'csv') {
            return $this->csv($report, $tab, $filters);
        }

        // El resumen y el CSV usan todas las filas; la tabla solo muestra una página.
        if ($report) {
            $report['data'] = (new LengthAwarePaginator(
                collect($report['data'])->forPage(Paginator::resolveCurrentPage(), self::PER_PAGE)->values(),
                count($report['data']),
                self::PER_PAGE,
                options: ['path' => $request->url()],
            ))->withQueryString();
        }

        return view('attendances.report', [
            'tab' => $tab,
            'filters' => $filters,
            'report' => $report,
            'filterErrors' => $validator->errors(),
            'people' => User::staff()->orderBy('lastname')->orderBy('name')->get(['id', 'name', 'lastname', 'dni']),
        ]);
    }

    /**
     * Los resultados como CSV, que Excel abre directamente.
     *
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $filters
     */
    private function csv(array $report, string $tab, array $filters): StreamedResponse
    {
        $withPerson = $tab === 'fecha';
        $filename = $withPerson
            ? "asistencias-{$filters['date']}.csv"
            : "asistencias-{$report['person']['dni']}-{$filters['from']}-{$filters['to']}.csv";

        return response()->streamDownload(function () use ($report, $withPerson) {
            $out = fopen('php://output', 'w');
            // El BOM hace que Excel reconozca los acentos.
            fwrite($out, "\u{FEFF}");

            $base = ['Fecha', 'Turno', 'Entrada', 'Salida', 'Zona', 'Estado', 'Observación'];
            fputcsv($out, $withPerson ? ['DNI', 'Apellidos y nombres', ...$base] : $base, ';');

            foreach ($report['data'] as $row) {
                $line = [
                    CarbonImmutable::parse($row['date'])->format('d/m/Y'),
                    $row['schedule'] ?? '',
                    $row['check_in'] ?? '',
                    $row['check_out'] ?? '',
                    $row['zone'] ?? '',
                    Status::label('attendance', $row['status']),
                    match ($row['exit_state'] ?? null) {
                        'anticipada' => 'Salida anticipada (el turno termina a las '.$row['shift_end'].')',
                        'sin_salida' => 'Sin salida',
                        default => '',
                    },
                ];

                fputcsv($out, $withPerson ? [$row['person']['dni'], $row['person']['full_name'], ...$line] : $line, ';');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
