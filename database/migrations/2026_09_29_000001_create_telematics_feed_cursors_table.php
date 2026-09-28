<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (integration, feed): where the provider's stats feed was left
 * and how healthy following it is. The cursor only advances in the same
 * transaction that stores the data it covers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telematics_feed_cursors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_integration_id')->constrained()->cascadeOnDelete();
            $table->string('feed');
            $table->text('end_cursor')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            // Newest point received through this feed: the lag is now() minus this.
            $table->timestamp('last_data_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('paused_until')->nullable();
            $table->text('last_error')->nullable();
            $table->json('last_cycle_json')->nullable();
            $table->timestamps();

            $table->unique(['tenant_integration_id', 'feed']);
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telematics_feed_cursors');
    }
};
