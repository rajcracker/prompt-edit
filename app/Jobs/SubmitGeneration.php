<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Pixelforge\PixelforgeException;
use App\Services\GenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Retries submitting a generation Pixelforge couldn't take right away
 * (overloaded, rate or concurrency limit, network error).
 *
 * Waits at least Retry-After, backs off exponentially, and gives up after a
 * few attempts, except while waiting for a concurrency slot, which is expected
 * when a user queues several images and is only bounded by a time limit.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 6;

    public const MAX_WAIT_MINUTES = 15;

    // Releases are counted as attempts by the worker; our own limits above decide when to stop.
    public int $tries = 200;

    public function __construct(public Generation $generation) {}

    public function handle(GenerationService $service): void
    {
        $generation = $this->generation->fresh();

        if (! $generation || $generation->status !== Generation::PENDING) {
            return;
        }

        try {
            $service->submit($generation);
        } catch (PixelforgeException $e) {
            if (! $e->isRetryable()) {
                return; // already failed and released by the service
            }

            $waitingForSlot = $e->errorCode === 'concurrency_limit_exceeded';
            $outOfAttempts = ! $waitingForSlot && $generation->submit_attempts >= self::MAX_ATTEMPTS;
            $outOfTime = $generation->created_at->lt(now()->subMinutes(self::MAX_WAIT_MINUTES));

            if ($outOfAttempts || $outOfTime) {
                $service->failLocally(
                    $generation,
                    Generation::ERROR_ABANDONED,
                    "{$e->userMessage()} We gave up after {$generation->submit_attempts} attempts. You were not charged.",
                );

                return;
            }

            $this->release(self::delay($e, $generation->submit_attempts));
        }
    }

    /** Seconds to wait: at least Retry-After, exponential backoff capped at a minute, plus jitter. */
    public static function delay(PixelforgeException $e, int $attempt): int
    {
        $backoff = $e->errorCode === 'concurrency_limit_exceeded' ? 5 : min(60, 2 ** $attempt);

        return max($e->retryAfter ?? 0, $backoff) + random_int(0, 2);
    }
}
