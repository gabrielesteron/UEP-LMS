<?php

namespace App\Console\Commands;

use App\Services\CurriculumImport;
use Illuminate\Console\Command;

class ImportCurriculum extends Command
{
    protected $signature = 'lms:import-curriculum {file? : CSV path; defaults to the bundled UEP curriculum} {--validate : Validate the source only; no database access} {--dry-run : Check source and existing records without writing} {--force : Permit an explicit production import}';

    protected $description = 'Validate and idempotently import curriculum subjects and prerequisite IDs without creating classes or users';

    public function handle(CurriculumImport $import): int
    {
        try {
            $rows = $import->read($this->argument('file') ?: database_path('data/UEP_demo_courses_import.csv'));
            if ($this->option('validate')) {
                $result = $import->summary($rows);
            } else {
                if (! $this->option('dry-run') && app()->environment('production') && ! $this->option('force')) {
                    $this->error('Production import requires --force. Run --dry-run first.');

                    return self::FAILURE;
                }
                $result = $import->run($rows, (bool) $this->option('dry-run'));
            }
            $this->table(['Measure', 'Count'], collect($result)->map(fn ($value, $key) => [$key, $value])->all());
            $this->info($this->option('validate') || $this->option('dry-run') ? 'Validated; no data written.' : 'Curriculum imported. Existing academic history preserved.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
