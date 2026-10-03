# UEP LMS workflow improvements

Implemented and verified on 3 October 2026 in the existing Laravel 12 / Blade / Bootstrap project.

## Preserved architecture and data

- The three modules remain Academic Management, Learning & Activities, and Student Monitoring.
- `config/lms.php` still hides advanced features by default. Announcements and Profile remain accessible.
- All 65 existing routes retain their method, path, name, controller action and middleware. Eight additional routes support the wizard and bulk saves (73 total).
- No migrations were added or modified. Models, authentication, roles, database structure, Composer dependencies, environment files and Vercel deployment configuration were not changed.
- Existing CRUD, single attendance/grading endpoints, submission versions, academic history, schedule conflicts and attendance audit rules remain available.
- No production database writes, migrations, seeding or deployment were performed during this work. Browser checks used a separate local SQLite database.

## Admin: School Year Setup

Open **+ Set Up School Year** from the dashboard or Academic Management sidebar.

1. Select an existing academic year or enter a new name and date range. Select semester, Program and Year Level.
2. Add, rename or remove blocks on one screen.
3. Select existing subjects or enter codes, names and whole-number units. An existing code reuses the shared subject without overwriting its details.
4. Assign each subject to a teacher and one or more blocks. Each block/subject pair has one teacher.
5. Select existing students or preview a CSV. Select the target block for each student.
6. Review the target year, subjects, teachers, blocks, student count and schedule choice. Check confirmation and choose **Create Academic Setup**.

The draft lives in the authenticated admin's existing session. Steps create no academic records or users. The confirmed save is one transaction: validation, uniqueness or schedule conflicts roll back the entire setup. Draft tokens, review invalidation and session locking prevent stale submissions; replaying a successful request does not create another setup.

Each student has one current block in the existing schema. The wizard updates that placement and adds the new class enrollments while retaining earlier enrollments, attendance, grades and submissions. This differs deliberately from deleting old enrollments when moving between academic periods.

Checkbox selections persist across pages after **Save Selection**. Save before changing pages or searching. Editing an earlier step clears later dependent choices so block/subject indexes cannot silently point to different records.

Limits per setup: 30 blocks, 50 subjects, 100 assignment rows / 300 expanded classes, 500 students and 10,000 enrollment relationships. Use existing management pages for further individual changes.

## CSV import

Download the template in the Students step. Required headers:

```csv
Student ID,Name,Email,Block
2026-0001,Example Student,student@example.edu,3J
```

- UTF-8 comma-separated CSV; BOM and friendly header capitalization are supported.
- Maximum 1 MB / 500 data rows. Preview is paginated at 50 rows.
- Student ID maps to the existing `students.student_number`; Name maps to `users.name`.
- Block must match a block in the draft.
- Preview checks required values, email format, malformed CSV, duplicate IDs/emails, unavailable blocks, existing identity mismatches, archived accounts and role conflicts.
- Every row shows whether it invites a new student or reuses an existing matching account. Header/file errors and invalid rows block review and creation.
- An existing matching student is reused. An existing student-role account without a profile can receive the missing profile; account details and authentication are preserved.
- Identities are resolved again at creation, rather than trusting preview IDs.
- New accounts are inactive and receive an unusable random hashed secret. The existing signed activation flow verifies their email and saves their own normally hashed password.
- Uploaded CSV files are read from PHP temporary storage and are not saved as LMS uploads. No accounts, invitations or profiles are persisted during preview.

New account invitations use the existing database jobs table. They are inserted with the confirmed setup transaction and sent by a queue worker. A successful save reports how many were queued, not that external email delivery has completed.

## Duplicate Previous Setup

Open **Duplicate Previous Setup** from the dashboard or the first wizard step.

Choose source year, semester, Program and Year Level. Optionally copy basic schedules and current students. Choose the target academic year/semester in the wizard and review all six steps.

New blocks/classes receive new IDs; subjects are reused. Student accounts and profiles are reused. Learning content, assignment submissions, attendance and grades are not copied or replaced. Earlier records remain available. Target block collisions and teacher/block/room schedule conflicts stop saving without partial records. Removing a copied block disables schedule copying because its source mapping has changed; schedules can be added later through existing CRUD.

The Admin dashboard shows Needs Attention only when counts are nonzero: missing academic prerequisites, blocks without subjects, unavailable teachers, incomplete student profiles and missing schedules. Links open the corresponding existing management pages.

## Teacher experience

- **My Classes → Class Dashboard** exposes Overview, Learning and Monitoring sections.
- Quick actions open existing lesson/material/assignment forms, attendance and grading.
- Assignment forms emphasize Title, Instructions, Due Date, Points and Attachment. Description and text-submission settings remain in **More Options**. **Save Draft** and **Publish** retain existing status values.
- Attendance supports **Mark All Present → change individual students → Save Attendance**. Minutes and statuses still follow the session's late threshold. The seven-day teacher limit, admin correction reason and audit entries remain enforced. Existing records are updated without duplicates; unchanged rows do not generate extra audit entries.
- **Save All Grades** validates every entered score against assignment points and saves the batch atomically. Blank scores leave work unchanged. Feedback, returned status and latest submission behavior remain intact. Stale versions and unrelated submissions are rejected.
- Students without work show **No submission yet**. Latest submissions from former enrollees remain visible and gradable without recreating their enrollment.
- Incomplete bulk form payloads fail before writes, including missing rows or partially truncated fields.

## Student experience

- **Needs Attention** lists unsubmitted/returned assignments with due and overdue state.
- **Recent Activity** shows a limited set of new learning content and recent grades.
- **My Progress** shows attendance and grade summaries. Excused attendance is excluded from the attendance denominator, as before.
- My Classes cards show subject, code, teacher and block; students cannot self-enroll.
- Assignment pages show instructions, deadline, points, attachment, submission time/status, score and feedback. Resubmission uses the existing version system; only latest work is shown while advanced features are hidden.
- Schedule uses responsive weekday cards with time, subject, teacher and room.
- Empty states explain unavailable classes/content/records instead of presenting blank sections.

## Performance

- My Classes uses 18 classes per page. Dashboard class previews are limited to six; attention to six and recent activity to eight.
- Dashboard attendance/grade calculations use SQL aggregates and scoped subqueries. Hidden quizzes and submission history are not loaded by default.
- CSV identities, teacher/student validation and existing subject resolution use batch lookups instead of a SELECT per row.
- New blocks, subjects, classes, users, profiles, enrollments and queue jobs use batch inserts/upserts where the existing models permit it.
- Bulk attendance loads the roster/records once. Grading loads submitted work and users in batches; unchanged grades do not send extra notifications.

## Implementation files

| Area | Files added or changed |
| --- | --- |
| Academic setup | New `AcademicSetupController.php`, `AcademicSetup.php`, `StudentCsvImport.php`, `SendSetupInvitation.php`, `routes/academic-setup.php`, `resources/views/admin/setup/` and `public/js/academic-setup.js` |
| Shared schedule checks | New `ScheduleConflicts.php`; existing `AdminController.php` calls the same extracted conflict query |
| Teacher saves | New `AssignmentGrading.php`, `routes/teacher-workflows.php`, `public/js/teacher-workflows.js`; updated `ClassController.php`, `AttendanceController.php` and `AttendanceService.php` |
| Dashboards and schedule | Updated `DashboardController.php`, dashboard/class/schedule Blade views; new `resources/views/dashboard/` partials and `classes/submission-details.blade.php` |
| Shared presentation | Updated layout navigation, `public/css/app.css`, assignment form and field labels in `Catalog.php` |
| Route integration | `routes/web.php` requires the two additive route files inside the existing protected group |
| Tests | New `AcademicSetupServiceTest`, `AcademicSetupWizardTest`, `StudentCsvImportTest`, `TeacherWorkflowTest`, `DashboardWorkflowTest`; one existing status-selector assertion adapted to the nested bulk form |
| Documentation | This report, `README.md`, `docs/DEPLOYMENT.md`, `docs/VERCEL.md` |

## Bugs found and fixed

- Optional CSV incorrectly prevented selected-student-only or empty-student setups.
- Nested wildcard duplicate validation rejected legitimate multiple subjects sharing a block.
- Repeated student identity validation introduced unnecessary queries.
- Earlier-step edits could leave stale block/subject choices or copied schedule mappings.
- Bulk grading used a base collection for Eloquent eager loading, causing a server error after grades saved. It now returns a successful redirect; regression tests assert the HTTP response as well as saved data.
- New roster-only grading could hide existing work from former enrollees. That work is retained without changing enrollment records.
- PHP form-input truncation could partially save a large roster; row/field completeness checks now reject it safely.
- Malformed block names could reach case-insensitive duplicate validation and trigger a server error; type validation now stops before that check.

## Verification

| Check | Result |
| --- | --- |
| Original baseline | 54 tests / 587 assertions passed before changes |
| Complete final SQLite suite | **110 tests / 1,347 assertions passed** |
| New coverage | Wizard review/confirmation/replay, CSV validation/identities, duplication/conflicts, atomic rollback, scoped dashboards, bulk saves, draft/publish, former submissions and role denials |
| Imported account integration | Actual queued job → normal signed invitation URL → activation fields → password hashing → login → enrolled classes; unauthorized pages denied |
| Existing routes | All 65 signatures preserved; 8 additions |
| Schema/auth/models/deployment | No changes |
| Production Blade and route compilation | Passed |
| PHP/JavaScript syntax | 85 PHP source files and 109 compiled views passed; both new JavaScript files passed |
| Git diff and secrets | Reviewed; no sensitive/local files or credential patterns in commit candidates |
| Browser at 1440px and 390px | Wizard, CSV/review, class workspace, attendance, grading, assignment submission, class cards, dashboard and visual schedule inspected |
| Real browser saves | 2 blocks / 3 classes / 2 students / 4 enrollments; duplicated year with 12 classes / 12 schedules; attendance present/late/absent; student resubmission; successful grading redirect |

Browser fixtures were confined to an ignored local test database. No real external email was sent. This pass did not rerun the suite against Railway/MySQL or exercise production S3/Gmail/queue infrastructure.

## Deployment considerations

No new migration or dependency installation is needed. Deploy the updated source through the existing process and rebuild the existing route/view caches.

**Bulk invitations require an external database queue worker.** On a trusted worker host using this same code revision and the same production MySQL, APP_KEY, APP_URL and SMTP environment, run:

```sh
php artisan queue:work database --queue=default --tries=3 --timeout=30
```

Keep it supervised and restart it on code releases. Check failed jobs and retry after resolving SMTP errors. Individual admin **Resend invitation** remains synchronous and can be used if the worker is not yet running. Vercel cannot host a persistent Artisan worker.

For very large rosters, configure PHP `max_input_vars` to at least 3500 for a 500-row attendance form (and adequate `post_max_size`). The application rejects incomplete payloads instead of partially saving. Check the PHP runtime's configured function duration for large setup saves; batch limits bound the work but do not guarantee network latency.

Existing Vercel limitations remain: persistent private S3-compatible storage, an external scheduler, and the [4.5 MB request/response transfer limit](https://vercel.com/docs/functions/limitations#request-body-size). Existing Laravel 20 MB upload/download flows still need a hosting/storage transfer solution for files above Vercel's limit. See [VERCEL.md](VERCEL.md).

## Final Admin → Teacher → Student flow

**Admin:** Dashboard → Set Up School Year (or load previous setup) → choose target period → blocks → subjects → teacher/multi-block assignments → select students / validate CSV → Review → Create. Worker delivers invitations; new students activate. Admin adds/corrects schedules through existing CRUD and monitors Needs Attention, attendance and grade reports.

**Teacher:** Dashboard → My Classes → select assigned class → add/publish lessons, materials and assignments → open submissions → Save All Grades → take attendance, Mark All Present, adjust exceptions and Save Attendance.

**Student:** Activate account and sign in → Needs Attention or My Classes → open class/assignment → learn and submit work → see latest submission status/feedback/score → view attendance, grades and weekly schedule.
