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
        Schema::create('attendance_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->time('default_entry_time')->default('08:00:00');
            $table->unsignedSmallInteger('tolerance_minutes')->default(15);
            $table->time('late_threshold_time')->default('08:45:00');
            $table->boolean('allow_teacher_override')->default(true);
            $table->boolean('require_justification_document')->default(false);
            $table->unsignedSmallInteger('max_retroactive_days')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['institution_id', 'academic_year_id']);
        });

        Schema::create('attendance_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->string('name');
            $table->string('shift')->default('mañana');
            $table->time('entry_time')->default('08:00:00');
            $table->unsignedSmallInteger('tolerance_minutes')->default(15);
            $table->time('late_threshold_time')->default('08:45:00');
            $table->jsonb('days_of_week')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['institution_id', 'shift', 'is_active']);
        });

        Schema::create('academic_calendar_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->date('date');
            $table->string('day_type')->default('school_day');
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_attendance_allowed')->default(true);
            $table->timestamps();

            $table->unique(['institution_id', 'date']);
            $table->index(['institution_id', 'day_type', 'date']);
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete();
            $table->date('date');
            $table->string('shift')->default('mañana');
            $table->string('session_type')->default('daily');
            $table->string('status');
            $table->time('entry_time')->nullable();
            $table->text('observation')->nullable();
            $table->foreignId('registered_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_justified')->default(false);
            $table->string('source')->default('manual');
            $table->timestamps();

            $table->unique(['enrollment_id', 'date', 'shift']);
            $table->index(['institution_id', 'date', 'section_id']);
        });

        Schema::create('attendance_justifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('attendance_record_id')->constrained('attendance_records')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->string('document_path')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('attendance_record_id')->nullable()->constrained('attendance_records')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_audit_logs');
        Schema::dropIfExists('attendance_justifications');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('academic_calendar_days');
        Schema::dropIfExists('attendance_schedules');
        Schema::dropIfExists('attendance_settings');
    }
};
