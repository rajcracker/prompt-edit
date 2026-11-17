<?php

namespace App\Http\Controllers;

use App\Models\CreditTransaction;
use App\Services\CreditLedger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    /** Packages users can "buy", in whole dollars. No real payment is taken. */
    public const PACKAGES = [1, 5, 10, 25];

    public function index(Request $request): Response
    {
        $transactions = $request->user()->creditTransactions()
            ->with('generation:id,uuid,prompt')
            ->latest('id')
            ->paginate(25);

        return Inertia::render('Credits', [
            'packages' => self::PACKAGES,
            'transactions' => $transactions->through(fn (CreditTransaction $t) => [
                'id' => $t->id,
                'type' => $t->type,
                'amount' => Money::toDecimal($t->amount_nanos),
                'balance_after' => Money::toDecimal($t->balance_after_nanos),
                'description' => $t->description,
                'prompt' => $t->generation?->prompt,
                'created_at' => $t->created_at->toIso8601ZuluString(),
            ]),
        ]);
    }

    public function store(Request $request, CreditLedger $ledger): RedirectResponse
    {
        $validated = $request->validate(['amount' => ['required', 'integer', Rule::in(self::PACKAGES)]]);

        $ledger->record(
            $request->user(),
            CreditTransaction::PURCHASE,
            $validated['amount'] * Money::NANOS_PER_DOLLAR,
            "Bought \${$validated['amount']} of credits",
        );

        return back()->with('success', "Added \${$validated['amount']}.00 to your balance.");
    }
}
