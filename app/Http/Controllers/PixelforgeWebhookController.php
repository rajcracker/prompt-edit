<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\GenerationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, GenerationService $service): Response
    {
        $payload = $request->getContent(); // the raw body, exactly as signed

        if (! $this->hasValidSignature($payload, (string) $request->header('Pixelforge-Signature'))) {
            return response('Invalid signature.', 400);
        }

        $event = json_decode($payload, true);

        if (! is_array($event) || empty($event['id']) || empty($event['type'])) {
            return response('Malformed event.', 400);
        }

        if ($event['type'] === 'ping') {
            return response()->noContent();
        }

        $remote = $event['data']['object'] ?? [];

        try {
            // Recording the event and applying it commit together: if applying fails,
            // the event isn't marked as seen and Pixelforge's retry gets another go.
            DB::transaction(function () use ($event, $remote, $request, $service) {
                WebhookEvent::create([
                    'event_id' => $event['id'],
                    'type' => $event['type'],
                    'pixelforge_generation_id' => $remote['id'] ?? null,
                    'payload' => $event,
                    'delivery_attempt' => (int) $request->header('Pixelforge-Delivery-Attempt', 1),
                ]);

                $generation = $this->findGeneration($remote);

                if (! $generation) {
                    Log::warning('Pixelforge webhook for an unknown generation', ['event' => $event['id']]);

                    return;
                }

                $service->applyRemote($generation, $remote);
            });
        } catch (UniqueConstraintViolationException) {
            // Already processed this event id: a duplicate delivery. Acknowledge it, change nothing.
        }

        return response()->noContent();
    }

    private function findGeneration(array $remote): ?Generation
    {
        // Matching by our uuid in metadata works even when the webhook beats our
        // create request's response and we don't know Pixelforge's id yet.
        $uuid = $remote['metadata']['generation_uuid'] ?? null;

        return ($uuid ? Generation::firstWhere('uuid', $uuid) : null)
            ?? (isset($remote['id']) ? Generation::firstWhere('pixelforge_id', $remote['id']) : null);
    }

    private function hasValidSignature(string $payload, string $header): bool
    {
        $secret = (string) config('services.pixelforge.webhook_secret');
        $parts = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = (int) ($parts['t'] ?? 0);
        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return $secret !== ''
            && hash_equals($expected, $parts['v1'] ?? '')
            && abs(time() - $timestamp) <= config('services.pixelforge.webhook_tolerance');
    }
}
