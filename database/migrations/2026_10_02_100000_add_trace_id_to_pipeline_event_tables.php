<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Id de traza del pipeline (App\Support\PipelineTrace). Se persiste en el
     * evento crudo y en el normalizado para que un reintento, un replay o una
     * reevaluación que entra por id recupere la MISMA traza, aunque el job que
     * lo despachó no la llevara en su Context. Nullable: los eventos anteriores
     * a esta migración no tienen traza.
     */
    public function up(): void
    {
        Schema::table('raw_events', function (Blueprint $table) {
            $table->ulid('trace_id')->nullable()->after('team_id');
            $table->index('trace_id');
        });

        Schema::table('normalized_events', function (Blueprint $table) {
            $table->ulid('trace_id')->nullable()->after('team_id');
            $table->index('trace_id');
        });
    }

    public function down(): void
    {
        Schema::table('normalized_events', function (Blueprint $table) {
            $table->dropIndex(['trace_id']);
            $table->dropColumn('trace_id');
        });

        Schema::table('raw_events', function (Blueprint $table) {
            $table->dropIndex(['trace_id']);
            $table->dropColumn('trace_id');
        });
    }
};
