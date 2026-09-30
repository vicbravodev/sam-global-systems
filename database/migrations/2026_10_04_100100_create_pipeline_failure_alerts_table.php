<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro (y candado de idempotencia) de las alertas por fallo definitivo del
 * pipeline: una fila por evento y etapa, con `dedup_key` único, de modo que un
 * mismo fallo nunca manda dos tandas de correos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_failure_alerts', function (Blueprint $table) {
            $table->id();
            // Nullable a propósito: si el evento que falló ya no existe no hay
            // tenant resoluble, y la alerta es sólo de plataforma (super-admins).
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('dedup_key')->unique();
            $table->string('kind');
            $table->string('stage');
            $table->unsignedBigInteger('raw_event_id')->nullable();
            $table->unsignedBigInteger('normalized_event_id')->nullable();
            $table->string('event_type_code')->nullable();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->boolean('is_emergency')->default(false);
            $table->jsonb('error_json')->nullable();
            $table->unsignedInteger('platform_recipients')->default(0);
            $table->unsignedInteger('tenant_recipients')->default(0);
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index(['kind', 'raw_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_failure_alerts');
    }
};
