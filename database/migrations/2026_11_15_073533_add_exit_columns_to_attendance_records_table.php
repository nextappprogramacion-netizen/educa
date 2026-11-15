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
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->time('exit_time')->nullable()->after('entry_time');
            $table->string('exit_type')->nullable()->after('exit_time'); // 'regular', 'early'
            $table->boolean('is_early_exit')->default(false)->after('exit_type');
            $table->text('early_exit_reason')->nullable()->after('is_early_exit');
            $table->foreignId('early_exit_authorized_by')->nullable()->after('early_exit_reason')->constrained('users')->nullOnDelete();
            $table->foreignId('exit_registered_by')->nullable()->after('early_exit_authorized_by')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropForeign(['early_exit_authorized_by']);
            $table->dropForeign(['exit_registered_by']);
            $table->dropColumn([
                'exit_time',
                'exit_type',
                'is_early_exit',
                'early_exit_reason',
                'early_exit_authorized_by',
                'exit_registered_by',
            ]);
        });
    }
};
