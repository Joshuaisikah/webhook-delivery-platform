<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();

            // Denormalised so the uniqueness rule can name the exact triple.
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            $table->enum('status', ['pending', 'processing', 'delivered', 'failed'])
                ->default('pending');

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);

            // NOT NULL so the claim query stays a single index range.
            $table->timestamp('next_attempt_at')->useCurrent();

            $table->char('locked_by', 36)->nullable();
            $table->timestamp('locked_at')->nullable();

            $table->unsignedSmallInteger('last_response_code')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();

            // Named explicitly so a violation can be told apart from the foreign
            // keys above, which raise the same SQLSTATE.
            $table->unique(
                ['application_id', 'webhook_id', 'event_id'],
                'webhook_deliveries_unique_delivery'
            );

            // Must match the claim query's WHERE and ORDER BY exactly.
            $table->index(['status', 'next_attempt_at', 'id'], 'webhook_deliveries_claim_index');

            $table->index('locked_by', 'webhook_deliveries_locked_by_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
