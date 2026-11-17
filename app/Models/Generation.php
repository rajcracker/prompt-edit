<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    use HasFactory;

    public const PENDING = 'pending';       // stored here, not yet accepted by Pixelforge

    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    /** Statuses only ever move forward through these ranks; final ones never change. */
    public const RANK = [
        self::PENDING => 0,
        self::QUEUED => 1,
        self::PROCESSING => 2,
        self::SUCCEEDED => 3,
        self::FAILED => 3,
    ];

    /** We stopped trying to submit it. Pixelforge may still have created it (see GenerationService). */
    public const ERROR_ABANDONED = 'submission_abandoned';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'image_url_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'settled_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::SUCCEEDED, self::FAILED], true);
    }
}
