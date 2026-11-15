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
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('institution_id')->nullable()->after('id')->constrained('institutions')->nullOnDelete();
            $table->string('role')->default('admin')->after('email'); // admin, teacher, assistant, guardian, superadmin
            $table->string('document_number')->nullable()->after('role');
            $table->string('phone')->nullable()->after('document_number');
            $table->boolean('is_active')->default(true)->after('phone');

            $table->index(['institution_id', 'role']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['institution_id']);
            $table->dropIndex(['institution_id', 'role']);
            $table->dropColumn(['institution_id', 'role', 'document_number', 'phone', 'is_active']);
        });
    }
};
