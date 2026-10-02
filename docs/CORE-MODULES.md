# Three core modules

The existing Laravel application now presents three navigation groups. Overview,
Announcements and Profile remain available outside those groups.

| Module | Admin | Teacher | Student |
| --- | --- | --- | --- |
| Academic Management | Academic years, programs, year levels, blocks, subjects, students, teachers, classes/teacher assignments, user accounts | My classes and class roster | My classes, subjects, year level, block and teacher |
| Learning & Activities | Monitor class content and submissions | Lessons, materials, assignments, submissions and grading | Lessons, materials, assignments and submissions |
| Student Monitoring | Attendance reports and grade reports | Record attendance, class gradebooks and reports | Attendance, grades and schedule |

Class enrollment and ordinary schedule setup remain accessible from the admin
Classes & Teacher Assignments screen. Existing schedule conflict validation remains
enforced. Admin activity screens provide monitoring controls; existing administrator
permissions and correction routes are retained.

## Hidden features

`config/lms.php` sets `show_advanced_features` to `false`. The main experience hides
quizzes, advanced report/export links, notifications center, global search, excuse
requests, attendance audit history, submission version history and attendance
settings. Set this presentation setting to `true` to restore their entry points.
No feature routes, controllers, models, tables or stored history were deleted.
Existing quiz scores still contribute to academic grade calculations.

The existing `/classes` route accepts a presentation-only `module=learning` or
`module=monitoring` query to show the relevant class selector. It uses the same
permission-scoped class query and opens the existing class page at its corresponding
section.

## Bugs corrected

- Whole-number inputs used a decimal minimum, making valid Year Level and lesson
  position values fail browser validation. Integer fields now use minimum 1 and
  step 1; assessment points retain decimal input.
- Year Level Name, Program, Year Level, Class and User Account labels are clearer.
  Programs remain separate records and placement is selected on the Block form.
- Required lesson/instruction text and initial material/file-only submission uploads
  now also have browser-side required validation, matching existing server rules.
- Generic catalog forms show field-specific validation and explain missing related
  records; saving is disabled when required relationship options are unavailable.
- Returned assignment status stays selected. Grading validation preserves entered
  score/feedback/status for the submission being edited.
- Assignment screens show the latest submission per student and preserve all
  versions in the database. The admin grading count uses those latest submissions.
- Attendance outside the teacher's seven-day edit window shows read-only controls;
  failed session creation preserves entered form values. Empty rosters show a message.
- Teacher announcements without a class now return validation errors instead of a
  missing-page response. Publishing is disabled for teachers without classes.
- Empty material references do not expose a broken download link.
- Admin catalog choice lists are loaded once per field and related labels use eager
  loading. Hidden notification badges, quiz lists and audit panels no longer cause
  their unnecessary queries.
- Content actions, class cards and calendar badges wrap on small screens.

## Verification

- Baseline: 42 tests, 320 assertions passed.
- Updated suite: **54 tests, 587 assertions passed** on isolated SQLite databases.
- All **65 Laravel routes** match the baseline; PHP syntax and Git whitespace checks
  passed. Routes, authentication, models, migrations and schema are unchanged.
- Every role's sidebar link loads in feature tests; existing role and class access
  denial tests still pass. Advanced feature routes and restoration are tested.
- Browser checks use a separate temporary SQLite database. Admin Year Level creation,
  teacher lesson publication, student submission and teacher grading succeeded.
  Screens were inspected at 1440 px and 390 px, including the mobile menu.
- No production migrations, seeds or demo accounts are needed for this update.

## Existing hosting attention

Configure persistent private S3-compatible storage for materials, submissions and
profile photos. Vercel temporary files are not durable. The existing 20 MB Laravel
file allowance exceeds Vercel's 4.5 MB request/response limit; larger transfers still
need a separate storage transfer flow or conventional PHP hosting. Background
scheduled commands still require the external scheduler described in
[VERCEL.md](VERCEL.md). These hosting limitations are unchanged by this UI pass.
