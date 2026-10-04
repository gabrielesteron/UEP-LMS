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
            // Shared (legacy) subjects still have globally unique codes; scoped
            // curriculum subjects use the program/year/semester/code constraint.
            $table->string('shared_code')->nullable()->virtualAs('case when program_id is null then code else null end');
            $table->unique('shared_code');
        });
    }

    public function down(): void
    {
        if (DB::table('subjects')->whereNotNull('program_id')->exists()
            || DB::table('subject_prerequisites')->exists()) {
            throw new LogicException('Curriculum rollback requires a reviewed data migration; no records were removed.');
        }
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropUnique(['shared_code']);
            $table->dropColumn('shared_code');
        });
    }
};
