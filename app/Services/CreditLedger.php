<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only place a user's balance changes. Every change is a ledger row, and
 * users.balance_nanos is kept equal to the sum of that user's rows.
 */
class CreditLedger
{
    public function record(User $user, string $type, int $amountNanos, string $description, ?Generation $generation = null): CreditTransaction
    {
        return DB::transaction(function () use ($user, $type, $amountNanos, $description, $generation) {
            // Lock the user so concurrent changes to the same balance are applied one at a time.
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $balance = $locked->balance_nanos + $amountNanos;

            $transaction = $locked->creditTransactions()->create([
                'generation_id' => $generation?->id,
                'type' => $type,
                'amount_nanos' => $amountNanos,
                'balance_after_nanos' => $balance,
                'description' => $description,
            ]);

            $locked->forceFill(['balance_nanos' => $balance])->save();
            $user->balance_nanos = $balance;

            return $transaction;
        });
    }
}
