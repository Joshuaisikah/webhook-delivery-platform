<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            // 500 to fit InnoDB's 3072-byte index key limit. utf8mb4_bin because
            // URL paths are case-sensitive.
            $table->string('url', 500)->collation('utf8mb4_bin');

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['application_id', 'url'], 'webhooks_application_url_unique');

            // Serves the fan-out query; the unique index above cannot.
            $table->index(['application_id', 'is_active', 'id'], 'webhooks_fanout_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
