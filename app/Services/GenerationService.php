<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\CostCalculator;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Owns a generation's lifecycle and every credit movement tied to it.
 *
 * Money rules, and where they are enforced:
 *  - The worst-case price is reserved (HOLD) before anything is sent to Pixelforge,
 *    so a user can never start more than they can afford, even concurrently.
 *  - When a generation becomes final the hold is RELEASEd, and a succeeded one is
 *    CHARGEd its actual price. Failed generations cost nothing.
 *  - Remote updates (create response, webhooks, polling) all go through applyRemote(),
 *    which locks the row, only moves the status forward, and settles exactly once
 *    (settled_at), backed by the unique (generation_id, type) index on the ledger.
 */
class GenerationService
{
    public function __construct(
        private readonly PixelforgeClient $pixelforge,
        private readonly CostCalculator $costs,
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * Store the generation and reserve its worst-case price. Does not call Pixelforge.
     *
     * @param  array{prompt: string, size: string, quality: string, reference_images?: array<int, string>}  $input
     *
     * @throws InsufficientCredits
     */
    public function create(User $user, array $input): Generation
    {
        $referenceImages = array_values($input['reference_images'] ?? []);
        $estimate = $this->costs->estimate(
            $input['prompt'], $input['size'], $input['quality'], count($referenceImages), $this->pixelforge->pricing(),
        );

        return DB::transaction(function () use ($user, $input, $referenceImages, $estimate) {
            $balance = User::query()->lockForUpdate()->findOrFail($user->id)->balance_nanos;

            if ($balance < $estimate['max_cost_nanos']) {
                throw new InsufficientCredits;
            }

            $generation = $user->generations()->create([
                'uuid' => (string) Str::uuid(),
                'status' => Generation::PENDING,
                'prompt' => $input['prompt'],
                'size' => $input['size'],
                'quality' => $input['quality'],
                'reference_images' => $referenceImages,
                'estimated_cost_nanos' => $estimate['expected_cost_nanos'],
                'max_cost_nanos' => $estimate['max_cost_nanos'],
            ]);

            $this->ledger->record($user, CreditTransaction::HOLD, -$estimate['max_cost_nanos'], 'Reserved for generation', $generation);

            return $generation;
        });
    }

    /**
     * Send a pending generation to Pixelforge. Safe to call repeatedly: our uuid is the
     * Idempotency-Key, so a retry after a timeout returns the original generation.
     *
     * @throws PixelforgeException Retryable errors are rethrown for the caller to schedule
     *                             a retry. Permanent ones fail the generation, then rethrow.
     */
    public function submit(Generation $generation): void
    {
        if ($generation->status !== Generation::PENDING || $generation->pixelforge_id) {
            return;
        }

        $generation->increment('submit_attempts');

        try {
            $remote = $this->pixelforge->createGeneration(array_filter([
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'reference_images' => $generation->reference_images ?: null,
                'metadata' => [
                    'generation_uuid' => $generation->uuid,
                    'user_id' => (string) $generation->user_id,
                ],
            ]), $generation->uuid);
        } catch (PixelforgeException $e) {
            if ($e->isRetryable()) {
                // Shown to the user while we wait to retry.
                $generation->update(['error_code' => $e->errorCode, 'error_message' => $e->userMessage()]);
            } else {
                $this->failLocally($generation, $e->errorCode, $e->userMessage());
            }

            throw $e;
        }

        $this->applyRemote($generation, $remote);
    }

    /** Give up on a generation Pixelforge never accepted. Releases the reservation. */
    public function failLocally(Generation $generation, string $code, string $message): void
    {
        DB::transaction(function () use ($generation, $code, $message) {
            $locked = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($locked->isFinal()) {
                return;
            }

            $locked->fill(['status' => Generation::FAILED, 'error_code' => $code, 'error_message' => $message]);
            $this->settle($locked);
            $generation->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Apply a generation object from Pixelforge (create response, webhook or poll).
     * Works with duplicates and any arrival order: stale updates are ignored.
     */
    public function applyRemote(Generation $generation, array $remote): Generation
    {
        return DB::transaction(function () use ($generation, $remote) {
            $locked = Generation::query()->lockForUpdate()->findOrFail($generation->id);
            $remoteStatus = $remote['status'] ?? null;

            if (! isset(Generation::RANK[$remoteStatus])) {
                Log::warning('Ignoring Pixelforge generation with unknown status', ['remote' => $remote]);

                return $locked;
            }

            $locked->pixelforge_id ??= $remote['id'];
            $locked->rates = $remote['rates'] ?? $locked->rates;
            $locked->last_synced_at = now();

            // Pixelforge accepted something we had given up on (an earlier attempt timed out
            // but went through). We told the user it failed, so we absorb the cost.
            $abandoned = $locked->status === Generation::FAILED && $locked->error_code === Generation::ERROR_ABANDONED;

            if ($abandoned && $remoteStatus === Generation::SUCCEEDED) {
                $this->fillFromRemote($locked, $remote);
                $locked->provider_cost_nanos = $this->costs->providerCost($locked->usage, $locked->rates);
                $locked->cost_nanos = 0;
                $locked->error_message = 'We stopped waiting for this image but Pixelforge made it after all. It is on us.';
                $locked->save();
            } elseif ($locked->isFinal()) {
                // Final states never change. A poll may still hand us a refreshed image URL.
                if ($locked->status === Generation::SUCCEEDED && ! empty($remote['image_url'])) {
                    $locked->image_url = $remote['image_url'];
                    $locked->image_url_expires_at = $this->time($remote['image_url_expires_at'] ?? null);
                }
                $locked->save();
            } elseif (Generation::RANK[$remoteStatus] > Generation::RANK[$locked->status]) {
                $this->fillFromRemote($locked, $remote);
                $locked->isFinal() ? $this->settle($locked) : $locked->save();
            } else {
                $locked->save(); // older or repeated news: only the bookkeeping above changes
            }

            $generation->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    private function fillFromRemote(Generation $generation, array $remote): void
    {
        $generation->status = $remote['status'];
        $generation->started_at = $this->time($remote['started_at'] ?? null) ?? $generation->started_at;
        $generation->completed_at = $this->time($remote['completed_at'] ?? null);

        if ($remote['status'] !== Generation::FAILED) {
            // Clear any "waiting to retry" note from submission.
            $generation->error_code = null;
            $generation->error_message = null;
        }

        if ($remote['status'] === Generation::SUCCEEDED) {
            $generation->usage = $remote['usage'];
            $generation->image_url = $remote['image_url'] ?? null;
            $generation->image_url_expires_at = $this->time($remote['image_url_expires_at'] ?? null);
        }

        if ($remote['status'] === Generation::FAILED) {
            $generation->error_code = $remote['error']['code'] ?? 'generation_failed';
            $message = $remote['error']['message'] ?? 'The generation failed.';
            // Pixelforge's messages usually say this already; make sure it's said exactly once.
            $generation->error_message = str_contains(strtolower($message), 'not charged') ? $message : "{$message} You were not charged.";
        }
    }

    /** Release the reservation and charge the actual price. Runs once per generation. */
    private function settle(Generation $generation): void
    {
        if ($generation->settled_at) {
            return;
        }

        $user = $generation->user;
        $this->ledger->record($user, CreditTransaction::RELEASE, $generation->max_cost_nanos, 'Reservation released', $generation);

        if ($generation->status === Generation::SUCCEEDED) {
            $generation->provider_cost_nanos = $this->costs->providerCost($generation->usage, $generation->rates);
            $generation->cost_nanos = $this->costs->userCost($generation->usage, $generation->rates);
            $this->ledger->record($user, CreditTransaction::CHARGE, -$generation->cost_nanos, 'Image generated', $generation);
        } else {
            $generation->cost_nanos = 0;
        }

        $generation->settled_at = now();
        $generation->save();
    }

    private function time(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value)->utc() : null;
    }
}
