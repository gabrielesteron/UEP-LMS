# Multiple programs in one academic year

The existing School Year Setup wizard can configure multiple Programs/Courses in one academic year and semester. Each program chooses its own year levels and blocks. Subjects and teachers are assigned explicitly to the selected blocks; student placement is resolved to that exact block.

## Workflow

1. Choose or create an Academic Year and choose the semester.
2. Add a Program (Course), select an existing program, and add its year levels. Select an existing year level or enter a new name and whole-number level. Add blocks beneath each year level. Repeat for other programs.
3. Choose existing subjects or enter new subject details.
4. Choose the subject and teacher, then select the intended blocks. Every choice includes Program, Year Level and Block.
5. Select existing students and their target blocks, or preview a CSV.
6. Review the grouped Program → Year Level → Block structure, subjects, teachers and student placements, then confirm creation.

Draft steps do not create academic records, year levels or accounts. Final creation remains a single transaction. Any validation or target collision rolls back the complete setup, including proposed year levels.

## Existing database relationships

No schema or model changes are required. A block already stores Academic Year, Semester, Program and Year Level. A classroom stores Block, Subject and Teacher. Student placement and enrollments use these block/classroom IDs.

Year levels are a shared catalog with unique numeric levels in the existing schema. Choosing Level 2 for two programs reuses that catalog entry; each program still has separate blocks and classes. A proposed existing level number reuses its saved name without renaming it. New level numbers are created once on confirmation. Programs do not automatically receive the same levels, subjects or teachers.

Repeated block names are supported in different Program/Year Level scopes. For example, BSIT → Level 2 → 2A and BSBA → Level 2 → 2A have different block IDs. Duplicate names within the same program, year level, academic year and semester are rejected.

## CSV placement

The original four columns remain supported when a block name identifies exactly one target. Use the scoped template for setups with repeated names:

```csv
Student ID,Name,Email,Program,Year Level,Block
2026-0001,Example IT Student,it.student@example.edu,BSIT,2,2A
2026-0002,Example Business Student,business.student@example.edu,BSBA,2,2A
```

Program accepts its catalog code or name. Year Level accepts its catalog name or whole-number level. Missing qualifiers that leave more than one matching target produce a row error. An incorrect Program or Year Level never falls back to another block. Preview and final confirmation resolve placement again from the authoritative draft; submitted block indices are not trusted.

Historical enrollments, grades, attendance, submissions and account authentication remain preserved when moving selected students to their current block. New CSV accounts still use the existing signed invitation and activation flow. Invitation delivery requires the existing external database queue worker on Vercel.

## Bounds and compatibility

The setup supports up to 20 program groups, 12 year levels per program and 30 blocks total, with the existing 50-subject, 300-class, 500-student and 10,000-enrollment limits. Nested form counts detect truncated submissions. Existing single-program draft payloads, setup routes and previous-setup copying remain compatible.

## Verification

Verified on 3 October 2026:

- Full isolated SQLite suite: **122 tests / 1,535 assertions passed**.
- All 73 route signatures unchanged; migrations, models and authentication unchanged.
- Three-program integration confirms ten distinct blocks, different subjects/teachers, correctly scoped CSV enrollment, preserved previous enrollments, and role-access restrictions.
- Validation covers duplicate scoped names, decimal level numbers, truncated structures, malformed old input, CSV ambiguity/mismatches and authoritative placement resolution.
- A conflict in one program rolls back the complete transaction, including a proposed new year level.
- Real local browser flow created ten blocks/classes across BSIT, BSBA and BSED; a new Level 5 was created only at confirmation. Three existing students received the correct block/subject/teacher while existing grades, attendance and submissions were retained.
- Desktop (1440 px) and mobile (390 px) grouped editing/review showed no page horizontal overflow; dynamic add/remove controls retained unique field IDs. No inspected browser console errors.
- Blade compilation, JavaScript syntax and staged secret scans passed.

Browser fixtures and database writes were restricted to an ignored local SQLite test database. No production academic records were created or changed for verification. This update requires no production migrations or seeding.
