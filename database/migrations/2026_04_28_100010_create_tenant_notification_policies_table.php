<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_notification_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('policy_code');
            $table->string('notification_type')->nullable();
            $table->string('priority')->nullable();
            $table->jsonb('allowed_channels_json');
            $table->jsonb('fallback_channels_json')->nullable();
            $table->jsonb('recipient_rules_json')->nullable();
            $table->jsonb('quiet_hours_json')->nullable();
            $table->jsonb('escalation_rules_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['team_id', 'notification_type']);
            $table->index(['team_id', 'policy_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_notification_policies');
    }
};
