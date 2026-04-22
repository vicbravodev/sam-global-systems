<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_objects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('bucket');
            $table->string('object_key');
            $table->string('original_filename')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('content_type')->nullable();
            $table->string('checksum')->nullable();
            $table->string('visibility')->default('private');
            $table->string('category');
            $table->nullableMorphs('fileable');
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['bucket', 'object_key']);
            $table->index(['team_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_objects');
    }
};
