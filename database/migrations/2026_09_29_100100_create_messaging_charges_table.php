<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Una fila por recurso Twilio (mensaje o llamada) que SAM originó en nombre
     * de un tenant: notificaciones, OTP y llamadas de verificación. Es la fuente
     * única de "cuánto nos costó Twilio por tenant": el reconciliador consulta
     * el recurso hasta que es terminal y trae precio, y entonces registra el
     * costo en el meter `messaging_cost_micros` (cobro cost-plus).
     *
     * `next_check_at` implementa el backoff del reconciliador por fila.
     */
    public function up(): void
    {
        Schema::create('messaging_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('twilio');
            $table->string('resource_type');
            $table->string('provider_sid')->unique();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('channel_type');
            $table->string('status')->nullable();
            $table->string('error_code')->nullable();
            $table->unsignedSmallInteger('segments')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedBigInteger('price_micros')->nullable();
            $table->string('price_unit', 8)->nullable();
            $table->boolean('price_estimated')->default(false);
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('metered_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->unsignedSmallInteger('check_attempts')->default(0);
            $table->jsonb('events_json')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index(['source_type', 'source_id']);
            $table->index(['finalized_at', 'next_check_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_charges');
    }
};
