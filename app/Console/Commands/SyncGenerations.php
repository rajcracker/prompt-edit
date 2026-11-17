<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Services\GenerationService;
use Illuminate\Console\Command;

/**
 * Safety net for webhooks: delivery isn't guaranteed, so generations that have
 * been quiet for a while are fetched from Pixelforge and brought up to date.
 */
class SyncGenerations extends Command
{
    protected $signature = 'generations:sync {--limit=10}';

    protected $description = 'Poll Pixelforge for generations that have not reached a final status';

    public function handle(PixelforgeClient $pixelforge, GenerationService $service): int
    {
        $stale = Generation::query()
            ->whereIn('status', [Generation::QUEUED, Generation::PROCESSING])
            ->whereNotNull('pixelforge_id')
            ->where('created_at', '<', now()->subSeconds(20))
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subSeconds(30)))
            ->orderBy('last_synced_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($stale as $generation) {
            try {
                $service->applyRemote($generation, $pixelforge->getGeneration($generation->pixelforge_id));
            } catch (PixelforgeException $e) {
                $this->warn("{$generation->pixelforge_id}: {$e->errorCode}");

                if ($e->errorCode === 'rate_limit_exceeded') {
                    break;
                }
            }
        }

        // A submit job that died (worker restart, deploy) leaves a generation pending forever.
        Generation::query()
            ->where('status', Generation::PENDING)
            ->where('updated_at', '<', now()->subMinutes(20))
            ->each(fn (Generation $g) => $service->failLocally(
                $g, Generation::ERROR_ABANDONED, 'We could not submit this image to Pixelforge. You were not charged.',
            ));

        $this->info("Synced {$stale->count()} generation(s).");

        return self::SUCCESS;
    }
}
