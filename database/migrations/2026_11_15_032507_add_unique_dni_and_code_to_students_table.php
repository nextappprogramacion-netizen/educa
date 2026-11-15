<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex(['institution_id', 'document_number']);
            $table->unique(['institution_id', 'document_number'], 'students_institution_id_document_number_unique');
            $table->unique(['institution_id', 'code'], 'students_institution_id_code_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique('students_institution_id_document_number_unique');
            $table->dropUnique('students_institution_id_code_unique');
            $table->index(['institution_id', 'document_number']);
        });
    }
};
