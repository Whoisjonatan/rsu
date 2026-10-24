<?php

namespace App\Services\Holidays;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Feriados nacionales del Perú para "Cargar feriados Perú".
 *
 * Salen del calendario público de feriados (config/holidays.php), que se
 * actualiza solo cada año. Si no responde, se usa una lista interna con las
 * fechas fijas de ley y la Semana Santa calculada, para que el botón nunca
 * quede inservible por falta de conexión.
 */
class PeruHolidays
{
    /**
     * @return array{source: 'online'|'local', holidays: list<array{date: string, description: string, status: string}>}
     */
    public function forYear(int $year): array
    {
        try {
            $holidays = Cache::remember(
                "holidays.peru.{$year}",
                now()->addHours((int) config('holidays.cache_hours')),
                fn () => $this->fetch($year),
            );

            return ['source' => 'online', 'holidays' => $holidays];
        } catch (Throwable $e) {
            Log::warning('No se pudo consultar el calendario de feriados', ['year' => $year, 'error' => $e->getMessage()]);

            return ['source' => 'local', 'holidays' => self::fallback($year)];
        }
    }

    /**
     * Descarga el .ics y se queda con los "Día festivo" del año. El calendario
     * también trae celebraciones (Día de la Madre, Nochebuena...) que no son
     * feriados y se descartan.
     *
     * Los días no laborables del sector público vienen marcados como festivos,
     * pero no son feriados nacionales: entran inactivos para que cada quien
     * decida si los activa.
     *
     * @return list<array{date: string, description: string, status: string}>
     */
    private function fetch(int $year): array
    {
        $response = Http::timeout((int) config('holidays.timeout'))->get(config('holidays.feed_url'));

        if (! $response->successful()) {
            throw new RuntimeException("El calendario respondió {$response->status()}.");
        }

        $holidays = [];

        foreach ($this->events($response->body()) as $event) {
            $date = $event['date'];

            if (! str_starts_with($date, "{$year}-") || ! str_starts_with($event['kind'], 'Día festivo') || isset($holidays[$date])) {
                continue;
            }

            $holidays[$date] = [
                'date' => $date,
                'description' => mb_substr($event['summary'], 0, 150),
                'status' => preg_match('/sector p[uú]blico|no laborable/iu', $event['summary']) ? 'inactivo' : 'activo',
            ];
        }

        if ($holidays === []) {
            throw new RuntimeException("El calendario no trae feriados de {$year}.");
        }

        ksort($holidays);

        return array_values($holidays);
    }

    /**
     * Lee los eventos de un iCalendar: fecha, título y descripción. Las líneas
     * largas vienen partidas (la continuación empieza con un espacio) y las
     * comas escapadas con barra.
     *
     * @return list<array{date: string, summary: string, kind: string}>
     */
    private function events(string $ics): array
    {
        $lines = explode("\n", preg_replace("/\r?\n[ \t]/", '', str_replace("\r\n", "\n", $ics)));
        $events = [];
        $current = null;

        foreach ($lines as $line) {
            if ($line === 'BEGIN:VEVENT') {
                $current = ['date' => '', 'summary' => '', 'kind' => ''];
            } elseif ($line === 'END:VEVENT' && $current !== null) {
                $events[] = $current;
                $current = null;
            } elseif ($current !== null && preg_match('/^(DTSTART|SUMMARY|DESCRIPTION)[^:]*:(.*)$/', $line, $match)) {
                $value = str_replace(['\\,', '\\;', '\\n'], [',', ';', "\n"], trim($match[2]));

                match ($match[1]) {
                    'DTSTART' => $current['date'] = preg_match('/^(\d{4})(\d{2})(\d{2})/', $value, $d) ? "{$d[1]}-{$d[2]}-{$d[3]}" : '',
                    'SUMMARY' => $current['summary'] = $value,
                    'DESCRIPTION' => $current['kind'] = $value,
                };
            }
        }

        return $events;
    }

    /**
     * Respaldo sin conexión: los de fecha fija del Decreto Legislativo 713 y
     * sus modificatorias (7 de junio, 23 de julio, 6 de agosto y 9 de
     * diciembre incluidos) más Jueves y Viernes Santo, que dependen de la
     * Pascua. Sirve para cualquier año.
     *
     * @return list<array{date: string, description: string, status: string}>
     */
    public static function fallback(int $year): array
    {
        $easter = self::easterSunday($year);

        $fixed = [
            '01-01' => 'Año Nuevo',
            '05-01' => 'Día del Trabajo',
            '06-07' => 'Batalla de Arica y Día de la Bandera',
            '06-29' => 'San Pedro y San Pablo',
            '07-23' => 'Día de la Fuerza Aérea del Perú',
            '07-28' => 'Fiestas Patrias',
            '07-29' => 'Fiestas Patrias',
            '08-06' => 'Batalla de Junín',
            '08-30' => 'Santa Rosa de Lima',
            '10-08' => 'Combate de Angamos',
            '11-01' => 'Día de Todos los Santos',
            '12-08' => 'Inmaculada Concepción',
            '12-09' => 'Batalla de Ayacucho',
            '12-25' => 'Navidad',
        ];

        $holidays = [
            ['date' => $easter->subDays(3)->toDateString(), 'description' => 'Jueves Santo', 'status' => 'activo'],
            ['date' => $easter->subDays(2)->toDateString(), 'description' => 'Viernes Santo', 'status' => 'activo'],
        ];

        foreach ($fixed as $monthDay => $description) {
            $holidays[] = ['date' => "{$year}-{$monthDay}", 'description' => $description, 'status' => 'activo'];
        }

        usort($holidays, fn (array $a, array $b) => strcmp($a['date'], $b['date']));

        return $holidays;
    }

    /**
     * Domingo de Pascua (algoritmo de Meeus/Jones/Butcher). Se calcula aquí
     * para no depender de la extensión calendar de PHP (easter_date).
     */
    public static function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day)->startOfDay();
    }
}
