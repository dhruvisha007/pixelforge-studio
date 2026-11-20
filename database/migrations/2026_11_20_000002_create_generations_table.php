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
            // Our public id. Also sent to Pixelforge as the Idempotency-Key and in metadata.
            $table->uuid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pixelforge_id')->nullable()->unique();
            $table->string('status', 20)->index();

            $table->text('prompt');
            $table->string('size', 20);
            $table->string('quality', 10);
            $table->json('reference_images');

            // Rates Pixelforge bills this generation at (USD per 1M tokens), fixed at creation.
            $table->json('rates')->nullable();

            // Money is in nanodollars (1 USD = 1,000,000,000).
            $table->bigInteger('estimated_min_charge_nanodollars');
            $table->bigInteger('estimated_max_charge_nanodollars');
            $table->bigInteger('provider_cost_nanodollars')->nullable();
            $table->bigInteger('charge_nanodollars')->nullable();

            // Actual usage, from Pixelforge once the generation succeeds.
            $table->unsignedInteger('input_text_tokens')->nullable();
            $table->unsignedInteger('input_image_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('image_path')->nullable();

            // Failed attempts to send it (overloads, network errors). Waiting for a free slot does not count.
            $table->unsignedSmallInteger('submit_failures')->default(0);
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            // When Pixelforge last told us about this generation (webhook, poll or create response).
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
