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
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('year');
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['institution_id', 'year']);
            $table->index(['institution_id', 'is_active']);
        });

        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->string('name'); // e.g. "1° Grado", "2° Grado"
            $table->string('level')->default('Primaria'); // Inicial, Primaria, Secundaria, Superior
            $table->unsignedSmallInteger('order_index')->default(1);
            $table->timestamps();

            $table->index(['institution_id', 'level', 'order_index']);
        });

        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('grade_id')->constrained('grades')->cascadeOnDelete();
            $table->string('name'); // e.g. "A", "B", "Única"
            $table->string('shift')->default('Mañana'); // Mañana, Tarde, Noche
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->string('room')->nullable();
            $table->timestamps();

            $table->unique(['institution_id', 'grade_id', 'name', 'shift']);
            $table->index(['institution_id', 'grade_id']);
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->string('code')->nullable(); // Código estudiante / SIAGIE
            $table->string('first_name');
            $table->string('last_name');
            $table->string('document_type')->default('DNI');
            $table->string('document_number')->nullable();
            $table->string('gender', 1)->nullable(); // M, F
            $table->date('birth_date')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('avatar_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['institution_id', 'last_name', 'first_name']);
            $table->index(['institution_id', 'document_number']);
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete();
            $table->string('enrollment_code')->nullable();
            $table->string('status')->default('active'); // active, transferred, withdrawn
            $table->date('enrolled_at')->nullable();
            $table->timestamps();

            $table->unique(['academic_year_id', 'student_id']);
            $table->index(['institution_id', 'section_id', 'status']);
        });

        Schema::create('section_teachers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->boolean('is_primary')->default(true);
            $table->timestamps();

            $table->unique(['section_id', 'user_id', 'academic_year_id']);
            $table->index(['institution_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('section_teachers');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('students');
        Schema::dropIfExists('sections');
        Schema::dropIfExists('grades');
        Schema::dropIfExists('academic_years');
    }
};
