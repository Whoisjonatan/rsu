<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserType extends Model
{
    protected $table = 'usertypes';

    /** Funciones que el informe exige preservar. */
    public const DRIVER = 'conductor';
    public const HELPER = 'ayudante';

    protected $fillable = ['name', 'slug', 'description', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'usertype_id');
    }
}
