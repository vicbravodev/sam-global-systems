<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('team_subscriptions')->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('subtotal', 10, 2);
            $table->decimal('overage_total', 10, 2);
            $table->decimal('total', 10, 2);
            $table->string('currency', 3);
            $table->string('status');
            $table->jsonb('breakdown_json')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_snapshots');
    }
};
