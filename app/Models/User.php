<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'lastname',
        'dni',
        'birthdate',
        'license',
        'license_category',
        'license_expiration',
        'phone',
        'address',
        'email',
        'password',
        'profile_photo_path',
        'status',
        'usertype_id',
        'zone_id',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = ['full_name'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'birthdate' => 'date:Y-m-d',
            'license_expiration' => 'date:Y-m-d',
            'password' => 'hashed',
        ];
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim($this->lastname.', '.$this->name, ', '));
    }

    public function usertype(): BelongsTo
    {
        return $this->belongsTo(UserType::class, 'usertype_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function vacations(): HasMany
    {
        return $this->hasMany(Vacation::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function occupancies(): HasMany
    {
        return $this->hasMany(VehicleOccupant::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'activo');
    }

    public function scopeOfFunction(Builder $query, string $slug): Builder
    {
        return $query->whereHas('usertype', fn (Builder $t) => $t->where('slug', $slug));
    }

    /** El informe solo permite programar a personal con contrato vigente. */
    public function hasActiveContractOn(string $date): bool
    {
        return $this->contracts()->activeOn($date)->exists();
    }

    /** El informe prohibe programar a personal de vacaciones. */
    public function isOnVacationOn(string $date): bool
    {
        return $this->vacations()->covering($date)->exists();
    }

    /**
     * De una lista de fechas, las que quedan fuera de todo contrato.
     *
     * La programacion valida el rango completo de golpe, asi que carga los
     * contratos una vez y compara en memoria en vez de consultar por fecha.
     *
     * @param  list<string>  $dates
     * @return list<string>
     */
    public function datesWithoutContract(array $dates): array
    {
        $contracts = $this->relationLoaded('contracts')
            ? $this->contracts
            : $this->contracts()->get();

        return array_values(array_filter(
            $dates,
            fn (string $date) => ! $contracts->contains(fn (Contract $c) => $c->coversDate($date)),
        ));
    }

    /**
     * De una lista de fechas, las que caen en vacaciones.
     *
     * @param  list<string>  $dates
     * @return list<string>
     */
    public function datesOnVacation(array $dates): array
    {
        $vacations = $this->relationLoaded('vacations')
            ? $this->vacations
            : $this->vacations()->get();

        return array_values(array_filter(
            $dates,
            fn (string $date) => $vacations->contains(fn (Vacation $v) => $v->coversDate($date)),
        ));
    }

    /** Sin marcacion del dia no se puede iniciar el recorrido. */
    public function attendedOn(string $date): bool
    {
        return $this->attendances()
            ->whereDate('date', $date)
            ->whereIn('status', ['presente', 'tarde'])
            ->exists();
    }

    /** El contrato que cubre hoy, si lo hay. */
    public function currentContract(?string $date = null): ?Contract
    {
        return $this->contracts()
            ->activeOn($date ?? now()->toDateString())
            ->latest('start_date')
            ->first();
    }

    /**
     * Columna ESTADO de W07: es un estado derivado del contrato y las
     * vacaciones de hoy, no la columna `status` de la tabla.
     */
    public function operationalStatus(?string $date = null): string
    {
        $date ??= now()->toDateString();

        if ($this->status !== 'activo') {
            return 'inactivo';
        }

        if ($this->isOnVacationOn($date)) {
            return 'vacaciones';
        }

        if ($this->hasActiveContractOn($date)) {
            return 'activo';
        }

        // "Culminado" solo si llegó a tener contrato; si nunca tuvo, es otra cosa.
        return $this->contracts()->exists() ? 'culminado' : 'sin_contrato';
    }

    /**
     * El informe exige validar la licencia; W08 avisa "licencia no vigente".
     * Un conductor sin licencia vigente no puede ser programado.
     */
    public function hasValidLicense(?string $date = null): bool
    {
        if (blank($this->license)) {
            return false;
        }

        return $this->license_expiration === null
            || $this->license_expiration >= ($date ? \Carbon\Carbon::parse($date) : now()->startOfDay());
    }

    public function isDriver(): bool
    {
        return $this->usertype?->slug === UserType::DRIVER;
    }

    /**
     * Login y terminal de marcación: el mismo campo acepta correo o DNI,
     * porque el personal marca asistencia con DNI y clave (W11) y la maqueta
     * usa un único campo "Usuario" (W01).
     */
    public static function findForLogin(string $username): ?self
    {
        return static::with('usertype')
            ->where('email', $username)
            ->orWhere('dni', $username)
            ->first();
    }

    /** URL pública de la foto (W08), o null si no tiene. */
    protected function photoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->profile_photo_path
            ? asset('storage/'.$this->profile_photo_path)
            : null);
    }

    /**
     * "Solo personal nombrado y a contrato permanente" puede registrar
     * vacaciones (W10).
     */
    public function isVacationEligible(): bool
    {
        return $this->contracts()
            ->activeOn(now()->toDateString())
            ->whereIn('type', Contract::PERMANENT_TYPES)
            ->exists();
    }

    /**
     * Filtros del listado de personal (W07). Los comparten el API y la vista
     * Blade, así los dos filtran exactamente igual.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        $today = now()->toDateString();
        $search = trim((string) ($filters['search'] ?? ''));

        return $query
            ->when($search !== '', function (Builder $q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(fn (Builder $inner) => $inner
                    ->where('dni', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('lastname', 'like', $like)
                    ->orWhere('email', 'like', $like));
            })
            ->when(filled($filters['usertype_id'] ?? null), fn (Builder $q) => $q
                ->where('usertype_id', (int) $filters['usertype_id']))
            // Por slug ("conductor", "ayudante"): los selectores de W18 saben
            // qué función necesitan, pero no el id que le tocó a esa función.
            ->when(filled($filters['usertype_slug'] ?? null), fn (Builder $q) => $q
                ->ofFunction((string) $filters['usertype_slug']))
            ->when(filled($filters['contract_type'] ?? null), fn (Builder $q) => $q
                ->whereHas('contracts', fn (Builder $c) => $c
                    ->where('type', (string) $filters['contract_type'])
                    ->activeOn($today)))
            // "Estado" aquí es el derivado de W07, no la columna `status`.
            ->when(filled($filters['state'] ?? null), function (Builder $q) use ($filters, $today) {
                match ((string) $filters['state']) {
                    'activo' => $q->where('status', 'activo')
                        ->whereHas('contracts', fn (Builder $c) => $c->activeOn($today))
                        ->whereDoesntHave('vacations', fn (Builder $v) => $v->covering($today)),
                    'vacaciones' => $q->whereHas('vacations', fn (Builder $v) => $v->covering($today)),
                    'culminado' => $q->where('status', 'activo')
                        ->has('contracts')
                        ->whereDoesntHave('contracts', fn (Builder $c) => $c->activeOn($today)),
                    'sin_contrato' => $q->where('status', 'activo')->doesntHave('contracts'),
                    'inactivo' => $q->where('status', 'inactivo'),
                    default => $q,
                };
            });
    }

    /**
     * Contadores de la barra superior de W07.
     *
     * @return array{total: int, con_contrato: int, de_vacaciones: int}
     */
    public static function staffSummary(): array
    {
        $today = now()->toDateString();

        return [
            'total' => static::count(),
            'con_contrato' => static::whereHas('contracts', fn (Builder $c) => $c->activeOn($today))->count(),
            'de_vacaciones' => static::whereHas('vacations', fn (Builder $v) => $v->covering($today))->count(),
        ];
    }

    /** Por qué no se puede eliminar a esta persona, o null si se puede. */
    public function deletionBlocker(?self $actor = null): ?string
    {
        if ($this->occupancies()->exists()) {
            return 'No se puede eliminar: la persona tiene recorridos asignados.';
        }

        if ($actor && $this->is($actor)) {
            return 'No puedes eliminar tu propio usuario.';
        }

        return null;
    }

    /** Elimina a la persona y su foto del disco. */
    public function deleteWithPhoto(): void
    {
        if ($this->profile_photo_path) {
            Storage::disk('public')->delete($this->profile_photo_path);
        }

        $this->delete();
    }

    /** Reemplaza la foto de perfil, borrando la anterior del disco. */
    public function replacePhoto(?UploadedFile $file): void
    {
        if ($this->profile_photo_path) {
            Storage::disk('public')->delete($this->profile_photo_path);
        }

        $this->update(['profile_photo_path' => $file?->store('people', 'public')]);
    }
}
