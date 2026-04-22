<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('usage_meter_id')->constrained()->cascadeOnDelete();
            $table->string('event_key');
            $table->unsignedBigInteger('quantity')->default(1);
            $table->jsonb('metadata_json')->nullable();
            $table->timestamp('occurred_at');
            $table->string('billing_period_key')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'event_key']);
            $table->index(['team_id', 'occurred_at']);
            $table->index(['usage_meter_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
    }
};
