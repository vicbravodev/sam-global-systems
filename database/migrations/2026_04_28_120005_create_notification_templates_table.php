<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('channel_type');
            $table->string('event_type')->nullable();
            $table->string('priority')->nullable();
            $table->string('subject_template')->nullable();
            $table->text('body_template');
            $table->jsonb('variables_schema_json')->nullable();
            $table->string('locale')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['team_id', 'channel_type', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
