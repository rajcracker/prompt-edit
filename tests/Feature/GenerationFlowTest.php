<?php

use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\CreditLedger;
use App\Services\GenerationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const SECRET = 'whsec_test';

const RATES = ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'];

beforeEach(function () {
    config([
        'services.pixelforge.webhook_secret' => SECRET,
        'services.pixelforge.api_key' => 'pf_test',
        'services.pixelforge.markup_percent' => 25,
    ]);
});

function pricingResponse(): array
{
    return [
        'object' => 'pricing',
        'rates' => RATES,
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ];
}

function remoteGeneration(Generation $g, string $status, array $extra = []): array
{
    return array_merge([
        'id' => 'gen_'.substr(md5($g->uuid), 0, 20),
        'object' => 'generation',
        'status' => $status,
        'prompt' => $g->prompt,
        'size' => $g->size,
        'quality' => $g->quality,
        'reference_images' => $g->reference_images,
        'metadata' => ['generation_uuid' => $g->uuid, 'user_id' => (string) $g->user_id],
        'image_url' => null,
        'image_url_expires_at' => null,
        'usage' => null,
        'rates' => RATES,
        'error' => null,
        'created_at' => '2026-11-04T14:03:11Z',
        'started_at' => in_array($status, ['processing', 'succeeded', 'failed']) ? '2026-11-04T14:03:13Z' : null,
        'completed_at' => in_array($status, ['succeeded', 'failed']) ? '2026-11-04T14:03:29Z' : null,
    ], $status === 'succeeded' ? [
        'image_url' => 'https://pixelforge.promptedit.dev/images/x.png?signature=abc',
        'image_url_expires_at' => '2026-11-05T14:03:29Z',
        'usage' => ['input_text_tokens' => 12, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1000, 'total_tokens' => 2112],
    ] : [], $status === 'failed' ? [
        'error' => ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'],
    ] : [], $extra);
}

/** Expected charge for the usage above: (12×5 + 1100×40 + 1000×12.5) per 1M = 56,560 → $0.05656, +25% = $0.0707 */
const EXPECTED_PROVIDER_NANOS = 56_560_000;
const EXPECTED_CHARGE_NANOS = 70_700_000;

function sendWebhook(array $remote, string $eventId, ?string $type = null, ?int $timestamp = null, string $secret = SECRET)
{
    $body = json_encode([
        'id' => $eventId,
        'object' => 'event',
        'type' => $type ?? 'generation.'.$remote['status'],
        'created_at' => '2026-11-04T14:03:29Z',
        'data' => ['object' => $remote],
    ]);
    $t = $timestamp ?? time();
    $signature = hash_hmac('sha256', "{$t}.{$body}", $secret);

    return test()->call('POST', '/webhooks/pixelforge', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => "t={$t},v1={$signature}",
    ], $body);
}

function userWithCredits(int $dollars = 10): User
{
    $user = User::factory()->create();
    app(CreditLedger::class)->record($user, CreditTransaction::PURCHASE, $dollars * 1_000_000_000, 'Test credits');

    return $user->fresh();
}

/** A generation that Pixelforge has accepted, with its worst-case price reserved. */
function acceptedGeneration(User $user): Generation
{
    Http::fake(['*/pricing' => Http::response(pricingResponse())]);
    $generation = app(GenerationService::class)->create($user, ['prompt' => 'A lighthouse at dawn', 'size' => '1024x1024', 'quality' => 'medium']);

    return app(GenerationService::class)->applyRemote($generation, remoteGeneration($generation, 'queued'));
}

it('rejects webhooks with a bad signature or an old timestamp', function () {
    $generation = acceptedGeneration(userWithCredits());

    sendWebhook(remoteGeneration($generation, 'succeeded'), 'evt_1', secret: 'whsec_wrong')->assertStatus(400);
    sendWebhook(remoteGeneration($generation, 'succeeded'), 'evt_1', timestamp: time() - 600)->assertStatus(400);

    expect($generation->fresh()->status)->toBe('queued');
});

it('charges the actual cost once a generation succeeds', function () {
    $user = userWithCredits(10);
    $generation = acceptedGeneration($user);

    expect($user->fresh()->balance_nanos)->toBe(10_000_000_000 - $generation->max_cost_nanos);

    sendWebhook(remoteGeneration($generation, 'succeeded'), 'evt_1')->assertNoContent();

    $generation->refresh();
    expect($generation->status)->toBe('succeeded')
        ->and($generation->provider_cost_nanos)->toBe(EXPECTED_PROVIDER_NANOS)
        ->and($generation->cost_nanos)->toBe(EXPECTED_CHARGE_NANOS)
        ->and($generation->image_url)->not->toBeNull()
        ->and($user->fresh()->balance_nanos)->toBe(10_000_000_000 - EXPECTED_CHARGE_NANOS)
        ->and($user->fresh()->balance_nanos)->toBe((int) $user->creditTransactions()->sum('amount_nanos'));
});

it('never records a duplicated event twice', function () {
    $user = userWithCredits(10);
    $generation = acceptedGeneration($user);
    $remote = remoteGeneration($generation, 'succeeded');

    sendWebhook($remote, 'evt_same')->assertNoContent();
    sendWebhook($remote, 'evt_same')->assertNoContent();
    // A different event carrying the same final state (e.g. a resend) must not charge again either.
    sendWebhook($remote, 'evt_other')->assertNoContent();

    expect(CreditTransaction::where('type', 'charge')->count())->toBe(1)
        ->and($user->fresh()->balance_nanos)->toBe(10_000_000_000 - EXPECTED_CHARGE_NANOS);
});

it('ignores events that arrive out of order', function () {
    $user = userWithCredits();
    $generation = acceptedGeneration($user);

    sendWebhook(remoteGeneration($generation, 'succeeded'), 'evt_2')->assertNoContent();
    sendWebhook(remoteGeneration($generation, 'processing'), 'evt_1')->assertNoContent();

    expect($generation->fresh()->status)->toBe('succeeded');
});

it('handles a webhook that arrives before the create request returned', function () {
    $user = userWithCredits();
    Http::fake(['*/pricing' => Http::response(pricingResponse())]);
    $generation = app(GenerationService::class)->create($user, ['prompt' => 'Fast one', 'size' => '1024x1024', 'quality' => 'medium']);

    // We don't know Pixelforge's id yet; the webhook is matched by our uuid in metadata.
    sendWebhook(remoteGeneration($generation, 'succeeded'), 'evt_1')->assertNoContent();
    // Then the (now stale) create response arrives.
    app(GenerationService::class)->applyRemote($generation->fresh(), remoteGeneration($generation, 'queued'));

    $generation->refresh();
    expect($generation->status)->toBe('succeeded')
        ->and($generation->pixelforge_id)->not->toBeNull()
        ->and($generation->cost_nanos)->toBe(EXPECTED_CHARGE_NANOS);
});

it('does not charge failed generations and releases the reservation', function () {
    $user = userWithCredits(10);
    $generation = acceptedGeneration($user);

    sendWebhook(remoteGeneration($generation, 'failed'), 'evt_1')->assertNoContent();
    sendWebhook(remoteGeneration($generation, 'succeeded'), 'evt_2')->assertNoContent(); // can't resurrect

    $generation->refresh();
    expect($generation->status)->toBe('failed')
        ->and($generation->cost_nanos)->toBe(0)
        ->and($generation->error_code)->toBe('generation_failed')
        ->and($user->fresh()->balance_nanos)->toBe(10_000_000_000);
});

it('submits a generation and shows the result to its owner', function () {
    $user = userWithCredits();
    Http::fake([
        '*/pricing' => Http::response(pricingResponse()),
        '*/generations' => fn ($request) => Http::response(remoteGeneration(Generation::first(), 'queued'), 202),
    ]);

    $this->actingAs($user)
        ->post('/generations', ['prompt' => 'A red fox', 'size' => '1536x1024', 'quality' => 'high', 'reference_images' => ['https://example.com/a.png', '']])
        ->assertSessionHasNoErrors();

    $generation = Generation::sole();
    expect($generation->status)->toBe('queued')->and($generation->reference_images)->toBe(['https://example.com/a.png']);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/generations')
        && $request->header('Idempotency-Key')[0] === $generation->uuid
        && $request['metadata']['generation_uuid'] === $generation->uuid);

    $this->get('/generate')->assertOk();
    $this->get('/generations')->assertOk();
});

it('blocks generating when the balance cannot cover the maximum cost', function () {
    $user = User::factory()->create();
    Http::fake(['*/pricing' => Http::response(pricingResponse())]);

    $this->actingAs($user)
        ->post('/generations', ['prompt' => 'A red fox', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertSessionHasErrors('credits');

    expect(Generation::count())->toBe(0);
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/generations'));
});

it('shows a content policy rejection and refunds the reservation', function () {
    $user = userWithCredits(1);
    Http::fake([
        '*/pricing' => Http::response(pricingResponse()),
        '*/generations' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'Rejected.']], 400),
    ]);

    $this->actingAs($user)
        ->post('/generations', ['prompt' => '[blocked] something', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertSessionHasErrors('prompt');

    expect(Generation::sole()->status)->toBe('failed')
        ->and($user->fresh()->balance_nanos)->toBe(1_000_000_000);
});

it('queues a retry when Pixelforge is overloaded, honouring Retry-After', function () {
    Queue::fake();
    $user = userWithCredits();
    Http::fake([
        '*/pricing' => Http::response(pricingResponse()),
        '*/generations' => Http::response(['error' => ['type' => 'api_error', 'code' => 'overloaded', 'message' => 'Overloaded.']], 503, ['Retry-After' => '2']),
    ]);

    $this->actingAs($user)
        ->post('/generations', ['prompt' => '[overloaded] x', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertSessionHasNoErrors();

    expect(Generation::sole()->status)->toBe('pending')->and(Generation::sole()->error_code)->toBe('overloaded');
    Queue::assertPushed(SubmitGeneration::class);
});

it('gives up after a few attempts without charging', function () {
    $user = userWithCredits(1);
    Http::fake([
        '*/pricing' => Http::response(pricingResponse()),
        '*/generations' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'Overloaded.']], 503, ['Retry-After' => '2']),
    ]);
    $generation = app(GenerationService::class)->create($user, ['prompt' => 'x', 'size' => '1024x1024', 'quality' => 'low']);
    $generation->update(['submit_attempts' => SubmitGeneration::MAX_ATTEMPTS - 1]);

    (new SubmitGeneration($generation))->handle(app(GenerationService::class));

    expect($generation->fresh()->status)->toBe('failed')
        ->and($generation->fresh()->error_code)->toBe(Generation::ERROR_ABANDONED)
        ->and($user->fresh()->balance_nanos)->toBe(1_000_000_000);
});

it('records the provider cost but does not charge the user if an abandoned generation succeeds after all', function () {
    $user = userWithCredits(1);
    Http::fake(['*/pricing' => Http::response(pricingResponse())]);
    $generation = app(GenerationService::class)->create($user, ['prompt' => 'x', 'size' => '1024x1024', 'quality' => 'medium']);
    app(GenerationService::class)->failLocally($generation, Generation::ERROR_ABANDONED, 'Gave up.');

    sendWebhook(remoteGeneration($generation, 'succeeded'), 'evt_1')->assertNoContent();

    $generation->refresh();
    expect($generation->status)->toBe('succeeded')
        ->and($generation->provider_cost_nanos)->toBe(EXPECTED_PROVIDER_NANOS)
        ->and($generation->cost_nanos)->toBe(0)
        ->and($user->fresh()->balance_nanos)->toBe(1_000_000_000);
});

it('polls Pixelforge for generations whose webhooks never came', function () {
    $user = userWithCredits();
    $generation = acceptedGeneration($user);
    $generation->forceFill(['created_at' => now()->subMinute(), 'last_synced_at' => now()->subMinute()])->save();
    Http::fake(['*/generations/*' => Http::response(remoteGeneration($generation, 'succeeded'))]);

    $this->artisan('generations:sync')->assertSuccessful();

    expect($generation->fresh()->status)->toBe('succeeded')->and($generation->fresh()->cost_nanos)->toBe(EXPECTED_CHARGE_NANOS);
});

it('lets users buy credits', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/credits', ['amount' => 5])->assertSessionHasNoErrors();
    $this->actingAs($user)->post('/credits', ['amount' => 7])->assertSessionHasErrors('amount');

    expect($user->fresh()->balance_nanos)->toBe(5_000_000_000);
    $this->get('/credits')->assertOk();
});

it('only shows the operator report to admins and reconciles it with Pixelforge', function () {
    $user = userWithCredits();
    $generation = acceptedGeneration($user);
    sendWebhook(remoteGeneration($generation, 'succeeded'), 'evt_1');

    Http::fake(['*/usage*' => Http::response([
        'object' => 'usage',
        'data' => [['date' => '2026-11-04', 'generations' => 1, 'cost_usd' => '0.056560000']],
        'total' => ['generations' => 1, 'cost_usd' => '0.056560000'],
    ])]);

    $this->actingAs($user)->get('/admin/report')->assertForbidden();

    config(['services.admin_emails' => [$user->email]]);
    $this->actingAs($user)
        ->get('/admin/report?start_date=2026-11-01&end_date=2026-11-05')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Report')
            ->where('totals.charged', '0.070700000')
            ->where('totals.provider_cost', '0.056560000')
            ->where('totals.profit', '0.014140000')
            ->where('days.1.date', '2026-11-04')
            ->where('days.1.matches', true));
});
