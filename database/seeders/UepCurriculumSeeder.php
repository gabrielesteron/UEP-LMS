<?php

namespace Database\Seeders;

use App\Services\CurriculumImport;
use Illuminate\Database\Seeder;

class UepCurriculumSeeder extends Seeder
{
    public function run(): void
    {
        $import = app(CurriculumImport::class);
        $import->run($import->read(database_path('data/UEP_demo_courses_import.csv')));
    }
}
