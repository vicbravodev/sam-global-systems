<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direcciones a las que no tiene sentido volver a mandar por un canal: el
 * destinatario respondió STOP/BAJA, el número es fijo o no tiene WhatsApp.
 * Antes cada aviso nuevo lo intentaba otra vez (cobrable o, al menos, lento
 * antes de caer al fallback).
 *
 * Fila de PLATAFORMA, sin team_id: el número remitente de Twilio es de SAM y
 * lo comparten todos los tenants, así que un STOP a ese número vale para
 * todos (Twilio mismo bloquea el envío con 21610). No guarda a qué tenant ni
 * qué aviso lo originó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_address_suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('channel_type');
            // E.164 sin prefijo `whatsapp:`.
            $table->string('address');
            $table->string('reason');
            $table->string('provider_error_code')->nullable();
            $table->string('source');
            $table->timestamp('suppressed_at');
            $table->timestamps();

            $table->unique(['channel_type', 'address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_address_suppressions');
    }
};
