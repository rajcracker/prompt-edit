<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the Pixelforge HTTP API. Every failure is turned into a
 * PixelforgeException carrying Pixelforge's error code and Retry-After, so callers
 * decide what to do (retry later, fail the generation, show an error).
 */
class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
    ) {}

    /** @return array<string, mixed> The generation object. */
    public function createGeneration(array $params, string $idempotencyKey): array
    {
        return $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/generations', $params));
    }

    /** @return array<string, mixed> The generation object. */
    public function getGeneration(string $id): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/generations/'.rawurlencode($id)));
    }

    /** @return array<string, mixed> The usage report for this API key. */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    /**
     * Current pricing. Cached briefly because it is read on every page load and
     * estimate; the last good copy is kept so an outage doesn't block estimates.
     *
     * @return array{rates: array<string, string>, tokens: array<string, mixed>}
     */
    public function pricing(): array
    {
        return Cache::remember('pixelforge.pricing', now()->addMinutes(5), function () {
            try {
                $pricing = $this->send(fn (PendingRequest $http) => $http->get('/pricing'));
                Cache::forever('pixelforge.pricing.last_good', $pricing);

                return $pricing;
            } catch (PixelforgeException $e) {
                return Cache::get('pixelforge.pricing.last_good') ?? throw $e;
            }
        });
    }

    private function send(callable $request): array
    {
        $http = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(20);

        if ($this->apiKey) {
            $http = $http->withToken($this->apiKey);
        }

        try {
            /** @var Response $response */
            $response = $request($http);
        } catch (ConnectionException $e) {
            throw new PixelforgeException('connection_error', $e->getMessage());
        }

        if ($response->successful()) {
            return $response->json();
        }

        $error = $response->json('error') ?? [];
        $retryAfter = $response->header('Retry-After');

        throw new PixelforgeException(
            errorCode: $error['code'] ?? ($response->serverError() ? 'server_error' : 'unknown_error'),
            message: $error['message'] ?? "Pixelforge responded with HTTP {$response->status()}.",
            status: $response->status(),
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
            param: $error['param'] ?? null,
        );
    }
}
