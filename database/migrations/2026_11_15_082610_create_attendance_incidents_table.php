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
        Schema::create('attendance_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();
            $table->foreignId('attendance_record_id')->nullable()->constrained('attendance_records')->nullOnDelete();
            $table->string('type'); // 'tardiness', 'card_forgotten', 'early_exit'
            $table->date('date');
            $table->time('time')->nullable();
            $table->text('reason'); // Motivo
            $table->string('responsible_name'); // Responsable (apoderado, auxiliar, tutor, etc.)
            $table->string('responsible_relationship')->nullable(); // 'Madre', 'Padre', 'Apoderado', 'Auxiliar', etc.
            $table->foreignId('registered_by')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('registered'); // 'registered', 'justified', 'pending'
            $table->boolean('is_justified')->default(false);
            $table->text('justification_reason')->nullable();
            $table->foreignId('justified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('justified_at')->nullable();
            $table->timestamps();

            $table->index(['institution_id', 'date', 'type']);
            $table->index(['student_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_incidents');
    }
};
