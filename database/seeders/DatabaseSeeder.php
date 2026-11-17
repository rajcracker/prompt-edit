<?php

namespace Database\Seeders;

use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\CreditLedger;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Local development only: a user (password "password") with $5 of credits. */
    public function run(CreditLedger $ledger): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $ledger->record($user, CreditTransaction::PURCHASE, 5_000_000_000, 'Bought $5 of credits');
    }
}
