<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_id')->nullable()->constrained()->nullOnDelete();
            // purchase | hold | release | charge
            $table->string('type');
            // Signed: credits added are positive, credits taken are negative.
            $table->bigInteger('amount_nanos');
            $table->bigInteger('balance_after_nanos');
            $table->string('description');
            $table->timestamps();

            // A generation can be held, released and charged at most once each.
            // This is the database-level guarantee that nothing is ever charged twice.
            $table->unique(['generation_id', 'type']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
