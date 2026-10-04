# UEP curriculum import

The bundled `database/data/UEP_demo_courses_import.csv` is the administrator-supplied file, copied without changes. SHA-256: `99e91ebed9125676ef21ead49c15c7cd9bf25055cef7f001401690f9a30de4cb`.

It contains 221 curriculum courses, 10 programs, four numeric year levels, two semesters and 133 prerequisite links. Program course counts: BSA 23; BSAT, BSBA, BSCE, BSCrim, BSEd, BSHRM and BSIT 24 each; BSMID 18; DMID 12. This is the supplied **demo curriculum**; it is not represented as an independently verified official curriculum.

## Existing structure retained

Subjects remain in `subjects` and all class foreign keys retain their IDs. Two additive migrations provide optional program/year/semester placement, decimal total/lecture/laboratory units, and `subject_prerequisites`. Shared subjects retain globally unique codes through a generated unique `shared_code`; curriculum subjects are unique by program/year/semester/code. Codes and titles are never prefixed or rewritten to resolve collisions. In particular OJT 401 and RES 301 retain their different program-specific titles and prerequisites.

Year levels remain the existing globally unique numeric catalog. Existing level IDs/names are reused; missing levels use the CSV's `N Year` names. Program names for missing programs come from the administrator's supplied program list in `config/curriculum.php`; existing program names remain intact. `1st` and `2nd` map to the existing semester values 1 and 2. A curriculum is reusable across academic years; the CSV contains no academic year and importing creates none.

The importer creates no users, blocks, classes, schedules, enrollments or learning/monitoring data. Matching legacy subjects are adopted only if their title/units match and every existing class already has exactly the specified program/year/semester. All other legacy subjects remain untouched.

## Commands

```sh
php artisan lms:import-curriculum --validate
php artisan migrate --path=database/migrations/2026_10_04_000000_add_curriculum_to_subjects.php --force
php artisan migrate --path=database/migrations/2026_10_04_000001_preserve_shared_subject_code_uniqueness.php --force
php artisan lms:import-curriculum --dry-run
php artisan lms:import-curriculum --force
```

The file argument is optional and defaults to the bundled CSV. Production writes require `--force`. `--validate` has no database access. `--dry-run` validates source and existing records without saving. Use the configured external production connection, never a placeholder or local database substituted for production. Keep credentials out of Git. Back up catalogs before a production migration/import. Deploy the new application after migrations and before importing program-specific duplicate codes.

`UepCurriculumSeeder` is an explicit optional seeder using the same service; it is deliberately **not** invoked by the demo `DatabaseSeeder`. No automatic import, seeding or migration runs in HTTP requests, Vercel builds or the scheduler.

## Validation and repeatability

The source must have the exact nine headers, valid UTF-8, bounded fields, valid program codes/year levels/semesters and decimal credits. Total units must equal lecture plus laboratory units. Duplicate scoped rows, unresolved/ambiguous prerequisites, self-references and cycles fail before writes. Same-semester prerequisites are retained as supplied (for example CE 302 → CE 301); chronological prerequisites are not invented.

Courses are saved first and then prerequisite IDs are resolved in the same transaction. Inserts are batched and unchanged courses retain timestamps. Re-running the same import creates no duplicate courses or links. Unexpected existing prerequisite links cause a full rollback and a review message; the importer does not silently delete them. Populated curriculum rollback is refused to protect data; reversing it requires a reviewed data migration.

## Admin workflows

Admin → Programs remains unchanged. Admin → Subjects adds program/year/semester filters, lecture/laboratory units and prerequisite display. Existing edit routes keep titles, descriptions, status and credit values editable; changing imported course codes or placement requires the validated importer to protect prerequisite IDs. Shared subject forms still work with blank curriculum fields.

The setup wizard lists subjects for its selected block scopes plus shared catalog subjects. Shared course codes are selected by exact subject ID with program/year/semester labels. Both wizard and direct teacher assignment forms reject cross-program/year/semester curriculum assignments. Prerequisite relationships are catalog information; this change does not introduce new enrollment eligibility rules.

## Verification

`CurriculumImportTest` verifies every source title/code/credit and prerequisite, idempotency and timestamps, dry-run safety, database uniqueness, legacy subject adoption, rollback, protected academic data, admin pages/credit validation, authorization and wizard class creation with duplicate codes. Existing regression tests must also pass. Run `php artisan test` and `php artisan view:cache` before publishing.

Implementation verification on 4 October 2026: all 130 tests passed (3,060 assertions), all 73 existing routes were unchanged, and Blade views compiled. An actual local MySQL import and repeat import preserved all unrelated academic table contents. Browser checks covered Programs, the four program-specific OJT 401 entries, prerequisite/credit display, editing and multi-program wizard subject selection.
