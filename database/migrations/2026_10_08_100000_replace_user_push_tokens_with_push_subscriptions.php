<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * El canal push pasa de FCM (tokens nunca registrados) a Web Push estándar.
 * user_push_tokens nunca tuvo filas reales: se elimina sin migrar datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('user_push_tokens');

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('public_key');
            $table->text('auth_token');
            $table->string('content_encoding', 16)->default('aes128gcm');
            $table->string('device_label')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');

        Schema::create('user_push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('platform');
            $table->string('token')->unique();
            $table->string('device_name')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index(['user_id', 'team_id']);
        });
    }
};
