<?php

namespace App\Models;

use App\Enums\ScanStatus;
use Database\Factories\ScanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ScanStatus $status
 * @property array<string, mixed>|null $detected_stack
 * @property list<array<string, mixed>>|null $skipped_files
 */
#[Fillable([
    'repository_id', 'user_id', 'status', 'commit_sha', 'slop_score', 'detected_stack',
    'skipped_files', 'lines_of_code', 'error_message', 'started_at', 'finished_at',
])]
class Scan extends Model
{
    /** @use HasFactory<ScanFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ScanStatus::class,
            'slop_score' => 'integer',
            'detected_stack' => 'array',
            'skipped_files' => 'array',
            'lines_of_code' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Keep the auto-increment id as primary key; the uuid is used in URLs
     * and for the scan's temp directory name.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Repository, $this> */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Finding, $this> */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    /** @return HasMany<Prompt, $this> */
    public function prompts(): HasMany
    {
        return $this->hasMany(Prompt::class);
    }

    /** @return HasMany<RulesFile, $this> */
    public function rulesFiles(): HasMany
    {
        return $this->hasMany(RulesFile::class);
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Move the scan to a new pipeline stage.
     */
    public function transitionTo(ScanStatus $status): void
    {
        $attributes = ['status' => $status];

        if ($status === ScanStatus::Fetching && $this->started_at === null) {
            $attributes['started_at'] = now();
        }

        if ($status->isTerminal()) {
            $attributes['finished_at'] = now();
        }

        $this->forceFill($attributes)->save();
    }

    public function markFailed(string $message): void
    {
        $this->forceFill([
            'status' => ScanStatus::Failed,
            'error_message' => $message,
            'finished_at' => now(),
        ])->save();
    }
}
