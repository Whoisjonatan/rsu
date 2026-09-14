<?php

namespace App\Http\Requests;

use App\Models\Route;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edicion de una ruta (W15).
 *
 * El informe pide "crear rutas por zonas, indicando punto de partida y fin".
 * Encima va el requisito de que no haya rutas duplicadas, que aqui se aplica
 * por partida doble: por nombre y por extremos.
 */
class RouteRequest extends FormRequest
{
    /**
     * Metros por debajo de los cuales dos extremos se consideran el mismo
     * punto. Exigir coordenadas identicas al decimal no serviria de nada:
     * nadie hace clic dos veces exactamente en el mismo pixel.
     */
    private const SAME_POINT_METERS = 50;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'latitude_start' => ['required', 'numeric', 'between:-90,90'],
            'longitude_start' => ['required', 'numeric', 'between:-180,180'],
            'latitude_end' => ['required', 'numeric', 'between:-90,90'],
            'longitude_end' => ['required', 'numeric', 'between:-180,180'],
            'description' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['activa', 'inactiva'])],
            // Una ruta pertenece al menos a una zona: es "crear rutas por zonas".
            'zone_ids' => ['required', 'array', 'min:1'],
            'zone_ids.*' => ['integer', 'exists:zones,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'zone_ids.required' => 'La ruta debe pertenecer al menos a una zona.',
            'zone_ids.min' => 'La ruta debe pertenecer al menos a una zona.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->checkDuplicates($validator);
        });
    }

    /**
     * Las dos lecturas de "no puede haber rutas duplicadas": mismo nombre en
     * una zona que ya lo usa, y mismos extremos que otra ruta de esa zona.
     *
     * Los extremos importan porque `routes` no guarda el camino intermedio:
     * dos rutas con la misma partida y el mismo fin son indistinguibles para
     * el sistema, por muy distinto que sea el recorrido real.
     */
    private function checkDuplicates(Validator $validator): void
    {
        $zoneIds = array_map('intval', $this->input('zone_ids', []));
        $currentId = $this->route('route')?->id;

        $siblings = Route::query()
            ->when($currentId, fn ($q) => $q->whereKeyNot($currentId))
            ->whereHas('zones', fn ($q) => $q->whereIn('zones.id', $zoneIds))
            ->with('zones')
            ->get();

        $name = mb_strtolower($this->input('name'));

        foreach ($siblings as $other) {
            $shared = $other->zones->whereIn('id', $zoneIds)->pluck('name')->implode(', ');

            if (mb_strtolower($other->name) === $name) {
                $validator->errors()->add('name', sprintf(
                    'Ya existe una ruta llamada "%s" en %s.',
                    $other->name,
                    $shared,
                ));

                continue;
            }

            if ($this->sameEndpoints($other)) {
                $validator->errors()->add('latitude_start', sprintf(
                    'La ruta "%s" de %s ya sale y llega a esos mismos puntos. Reutilízala en vez de duplicarla.',
                    $other->name,
                    $shared,
                ));
            }
        }
    }

    /** Comparación tolerante: los dos extremos, cada uno dentro del margen. */
    private function sameEndpoints(Route $other): bool
    {
        return $this->metersBetween(
            (float) $this->input('latitude_start'),
            (float) $this->input('longitude_start'),
            (float) $other->latitude_start,
            (float) $other->longitude_start,
        ) <= self::SAME_POINT_METERS
        && $this->metersBetween(
            (float) $this->input('latitude_end'),
            (float) $this->input('longitude_end'),
            (float) $other->latitude_end,
            (float) $other->longitude_end,
        ) <= self::SAME_POINT_METERS;
    }

    /** Haversine: distancia en metros entre dos coordenadas. */
    private function metersBetween(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $radius = 6_371_000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $radius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
