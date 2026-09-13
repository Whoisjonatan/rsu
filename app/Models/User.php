<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
}
