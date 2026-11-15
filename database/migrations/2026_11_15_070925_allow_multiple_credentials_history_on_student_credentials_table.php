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
        Schema::table('student_credentials', function (Blueprint $table) {
            $table->dropUnique(['institution_id', 'student_id']);
            $table->index(['institution_id', 'student_id']);
            $table->timestamp('invalidated_at')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_credentials', function (Blueprint $table) {
            $table->dropColumn('invalidated_at');
            $table->dropIndex(['institution_id', 'student_id']);
            $table->unique(['institution_id', 'student_id']);
        });
    }
};
