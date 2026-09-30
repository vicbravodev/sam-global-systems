<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canal `database` de Laravel Notifications. La tabla `notifications` ya es del
 * dominio Notifications (mensajería Twilio), así que las notificaciones in-app
 * de usuario viven aquí (App\Models\UserNotification, ruteado desde
 * User::notifications()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Nullable a propósito: un aviso de plataforma a un super-admin
            // (p. ej. un fallo sin tenant resoluble) no es de ningún tenant.
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
