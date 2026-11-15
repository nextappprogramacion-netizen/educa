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
        // Normalizar turnos y eliminar tolerancia existente antes de aplicar restricción
        DB::table('attendance_schedules')->where('shift', 'mañana')->update(['shift' => 'Mañana']);
        DB::table('attendance_schedules')->where('shift', 'tarde')->update(['shift' => 'Tarde']);

        Schema::table('attendance_schedules', function (Blueprint $table) {
            $table->time('exit_time')->nullable()->default('13:30:00')->after('entry_time');
            $table->unsignedSmallInteger('tolerance_minutes')->default(0)->change();
            $table->unique(['institution_id', 'shift'], 'attendance_schedules_institution_shift_unique');
        });

        // Asegurar tolerancia en cero para todos los registros en PostgreSQL
        DB::table('attendance_schedules')->update([
            'tolerance_minutes' => 0,
            'exit_time' => '13:30:00',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_schedules', function (Blueprint $table) {
            $table->dropUnique('attendance_schedules_institution_shift_unique');
            $table->dropColumn('exit_time');
            $table->unsignedSmallInteger('tolerance_minutes')->default(15)->change();
        });
    }
};
