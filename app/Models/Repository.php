<?php

namespace App\Models;

use App\Enums\ScanStatus;
use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['installation_id', 'github_repo_id', 'full_name', 'default_branch', 'is_private', 'removed_at'])]
class Repository extends Model
{
    /** @use HasFactory<RepositoryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'github_repo_id' => 'integer',
            'is_private' => 'boolean',
            'removed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Installation, $this> */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }

    /** @return HasMany<Scan, $this> */
    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }

    /** @return HasOne<Scan, $this> */
    public function latestScan(): HasOne
    {
        return $this->hasOne(Scan::class)->latestOfMany();
    }

    /** @return HasOne<Scan, $this> */
    public function latestCompletedScan(): HasOne
    {
        return $this->hasOne(Scan::class)->ofMany(
            ['id' => 'max'],
            fn (Builder $query) => $query->where('status', ScanStatus::Complete->value),
        );
    }

    /**
     * @param  Builder<Repository>  $query
     * @return Builder<Repository>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    public function owner(): ?User
    {
        return $this->installation?->user;
    }
}
