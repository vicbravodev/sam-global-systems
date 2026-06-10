<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_snapshots', function (Blueprint $table) {
            $table->foreignId('payment_receipt_file_object_id')
                ->nullable()
                ->constrained('file_objects')
                ->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_snapshots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_receipt_file_object_id');
            $table->dropColumn(['paid_at', 'payment_note']);
        });
    }
};
