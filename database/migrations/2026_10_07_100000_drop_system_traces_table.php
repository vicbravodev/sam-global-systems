<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `system_traces` no tenía escritor en producción (sólo el seeder de showcase)
 * y su único lector era `GET api/{team}/audit/traces/{traceId}`, que ninguna
 * pantalla usaba. La traza real de un evento ya existe: `PipelineTrace` lleva
 * el `trace_id` en cada línea de SystemLog y lo persiste en `raw_events` y
 * `normalized_events`. Se retira la tabla en vez de duplicar cada etapa del
 * pipeline en otra tabla append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('system_traces');
    }

    public function down(): void
    {
        Schema::create('system_traces', function (Blueprint $table) {
            $table->id();
            $table->uuid('trace_id');
            $table->uuid('span_id');
            $table->uuid('parent_span_id')->nullable();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('module_name');
            $table->string('operation_name');
            $table->string('status');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->jsonb('input_reference_json')->nullable();
            $table->jsonb('output_reference_json')->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('trace_id');
            $table->index(['team_id', 'module_name']);
            $table->index('span_id');
        });
    }
};
