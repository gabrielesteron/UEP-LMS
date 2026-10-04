<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->decimal('units', 6, 2)->change();
            $table->foreignId('program_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('year_level_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('semester')->nullable();
            $table->decimal('lecture_units', 6, 2)->nullable();
            $table->decimal('laboratory_units', 6, 2)->nullable();
            $table->unique(['program_id', 'year_level_id', 'semester', 'code'], 'subjects_curriculum_unique');
            $table->dropUnique('subjects_code_unique');
            $table->index('code');
        });
        Schema::create('subject_prerequisites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->foreignId('prerequisite_id')->constrained('subjects')->restrictOnDelete();
            $table->unique(['subject_id', 'prerequisite_id']);
        });
    }

    public function down(): void
    {
        // Restoring globally unique codes would lose valid program-specific courses.
        // Require a deliberate, reviewed data migration rather than deleting curriculum.
        if (DB::table('subjects')->whereNotNull('program_id')->exists()
            || DB::table('subject_prerequisites')->exists()) {
            throw new LogicException('Curriculum rollback requires a reviewed data migration; no records were removed.');
        }
        Schema::dropIfExists('subject_prerequisites');
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropUnique('subjects_curriculum_unique');
            $table->dropIndex('subjects_code_index');
            $table->dropForeign(['program_id']);
            $table->dropForeign(['year_level_id']);
            $table->dropColumn(['program_id', 'year_level_id', 'semester', 'lecture_units', 'laboratory_units']);
            $table->unique('code');
        });
    }
};
