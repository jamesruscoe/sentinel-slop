<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['github_id', 'username', 'name', 'email', 'avatar_url', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'github_id' => 'integer',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return HasMany<Installation, $this> */
    public function installations(): HasMany
    {
        return $this->hasMany(Installation::class);
    }

    /** @return HasManyThrough<Repository, Installation, $this> */
    public function repositories(): HasManyThrough
    {
        return $this->hasManyThrough(Repository::class, Installation::class);
    }

    /** @return HasMany<Scan, $this> */
    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }

    public function isAdmin(): bool
    {
        return in_array($this->username, config('sentinel.admins', []), true);
    }
}
