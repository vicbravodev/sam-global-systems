<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La clave de idempotencia de workflow_executions pasa a incluir team_id.
 *
 * Un workflow global (automation_workflows.team_id null) lo comparten todos
 * los tenants: sin team_id en el índice, dos tenants que lo disparaban con el
 * mismo source_type + source_reference_id (el trigger manual acepta la
 * referencia libre) chocaban con un unique violation — un 500 para el segundo
 * y, de paso, un oráculo de que otro tenant ya lo había corrido.
 * RunAutomationWorkflow ya busca la ejecución previa filtrando por team_id;
 * el índice ahora coincide con esa búsqueda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_executions', function (Blueprint $table) {
            $table->dropUnique('workflow_executions_idempotency_unique');
        });

        Schema::table('workflow_executions', function (Blueprint $table) {
            $table->unique(
                ['team_id', 'automation_workflow_id', 'source_type', 'source_reference_id'],
                'workflow_executions_idempotency_unique',
            );
        });
    }

    /**
     * Pre-producción: si ya hay dos tenants con la misma clave global, volver
     * al índice anterior falla; es el comportamiento esperado de un rollback
     * que reintroduce la restricción más estricta.
     */
    public function down(): void
    {
        Schema::table('workflow_executions', function (Blueprint $table) {
            $table->dropUnique('workflow_executions_idempotency_unique');
        });

        Schema::table('workflow_executions', function (Blueprint $table) {
            $table->unique(
                ['automation_workflow_id', 'source_type', 'source_reference_id'],
                'workflow_executions_idempotency_unique',
            );
        });
    }
};
