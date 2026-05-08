<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_executions', function (Blueprint $table) {
            $table->foreignId('output_file_object_id')
                ->nullable()
                ->after('file_path')
                ->constrained('file_objects')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('report_executions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('output_file_object_id');
        });
    }
};
