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
        Schema::create('campuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_main')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['institution_id', 'code']);
            $table->index(['institution_id', 'is_active']);
        });

        Schema::create('access_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('type')->default('mixed');
            $table->string('location_details')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['campus_id', 'code']);
            $table->index(['institution_id', 'campus_id', 'is_active']);
        });

        Schema::table('sections', function (Blueprint $table) {
            $table->foreignId('campus_id')->nullable()->after('grade_id')->constrained('campuses')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropForeign(['campus_id']);
            $table->dropColumn('campus_id');
        });

        Schema::dropIfExists('access_points');
        Schema::dropIfExists('campuses');
    }
};
