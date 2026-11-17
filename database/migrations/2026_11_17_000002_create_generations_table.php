<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            // Our own reference. Sent to Pixelforge as Idempotency-Key and in metadata,
            // so webhooks can be matched even before the create request has returned.
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pixelforge_id')->nullable()->unique();

            // pending (not yet accepted by Pixelforge) → queued → processing → succeeded | failed
            $table->string('status')->default('pending')->index();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // Money, all in nanodollars. Estimates are what we told the user up front;
            // max_cost is the worst case and is what we reserve from the balance.
            $table->bigInteger('estimated_cost_nanos');
            $table->bigInteger('max_cost_nanos');
            $table->bigInteger('provider_cost_nanos')->nullable();
            $table->bigInteger('cost_nanos')->nullable();

            $table->json('rates')->nullable();
            $table->json('usage')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->unsignedInteger('submit_attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            // Pixelforge's completed_at: the UTC day it is billed on.
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
