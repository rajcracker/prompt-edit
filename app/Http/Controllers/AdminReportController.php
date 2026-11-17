<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operator report: what users were charged, what Pixelforge charged us and the
 * profit, per UTC day and per user. Days follow Pixelforge's rule: a generation
 * is billed on the UTC day it *completed*. Our provider cost per day is shown
 * next to Pixelforge's own GET /v1/usage figure so they can be compared.
 */
class AdminReportController extends Controller
{
    public function __invoke(Request $request, PixelforgeClient $pixelforge): Response
    {
        abort_unless($request->user()->isAdmin(), 403);

        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        $end = Carbon::parse($validated['end_date'] ?? now('UTC')->toDateString(), 'UTC');
        $start = Carbon::parse($validated['start_date'] ?? $end->copy()->subDays(13)->toDateString(), 'UTC');
        if ($start->diffInDays($end) > 91) {
            $start = $end->copy()->subDays(91); // Pixelforge's usage API covers at most 92 days
        }

        $rows = Generation::query()
            ->where('status', Generation::SUCCEEDED)
            ->whereBetween('completed_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->selectRaw('DATE(completed_at) as day, user_id, COUNT(*) as generations, SUM(cost_nanos) as charged, SUM(provider_cost_nanos) as provider')
            ->groupBy('day', 'user_id')
            ->orderByDesc('day')
            ->get();

        $users = User::whereIn('id', $rows->pluck('user_id')->unique())->pluck('email', 'id');
        $usage = $this->pixelforgeUsage($pixelforge, $start->toDateString(), $end->toDateString());

        $days = [];
        for ($day = $end->copy(); $day->gte($start); $day->subDay()) {
            $date = $day->toDateString();
            $dayRows = $rows->where('day', $date);
            $charged = (int) $dayRows->sum('charged');
            $provider = (int) $dayRows->sum('provider');
            $reported = $usage['days'][$date] ?? null;

            $days[] = [
                'date' => $date,
                'generations' => (int) $dayRows->sum('generations'),
                'charged' => Money::toDecimal($charged),
                'provider_cost' => Money::toDecimal($provider),
                'profit' => Money::toDecimal($charged - $provider),
                'pixelforge_cost' => $reported,
                'matches' => $reported === null ? null : $reported === Money::toDecimal($provider),
                'users' => $dayRows->map(fn ($r) => [
                    'email' => $users[$r->user_id] ?? "User #{$r->user_id}",
                    'generations' => (int) $r->generations,
                    'charged' => Money::toDecimal((int) $r->charged),
                    'provider_cost' => Money::toDecimal((int) $r->provider),
                    'profit' => Money::toDecimal((int) $r->charged - (int) $r->provider),
                ])->values(),
            ];
        }

        $byUser = $rows->groupBy('user_id')->map(fn ($r, $userId) => [
            'email' => $users[$userId] ?? "User #{$userId}",
            'generations' => (int) $r->sum('generations'),
            'charged' => Money::toDecimal((int) $r->sum('charged')),
            'provider_cost' => Money::toDecimal((int) $r->sum('provider')),
            'profit' => Money::toDecimal((int) $r->sum('charged') - (int) $r->sum('provider')),
        ])->sortByDesc('generations')->values();

        $totalCharged = (int) $rows->sum('charged');
        $totalProvider = (int) $rows->sum('provider');

        return Inertia::render('Admin/Report', [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'days' => $days,
            'users' => $byUser,
            'totals' => [
                'generations' => (int) $rows->sum('generations'),
                'charged' => Money::toDecimal($totalCharged),
                'provider_cost' => Money::toDecimal($totalProvider),
                'profit' => Money::toDecimal($totalCharged - $totalProvider),
                'pixelforge_cost' => $usage['total'],
            ],
            'usage_error' => $usage['error'],
        ]);
    }

    /** @return array{days: array<string, string>, total: ?string, error: ?string} */
    private function pixelforgeUsage(PixelforgeClient $pixelforge, string $start, string $end): array
    {
        try {
            $report = Cache::remember("pixelforge.usage.{$start}.{$end}", 60, fn () => $pixelforge->usage($start, $end));
        } catch (PixelforgeException $e) {
            return ['days' => [], 'total' => null, 'error' => $e->getMessage()];
        }

        return [
            'days' => collect($report['data'])->pluck('cost_usd', 'date')->all(),
            'total' => $report['total']['cost_usd'] ?? null,
            'error' => null,
        ];
    }
}
