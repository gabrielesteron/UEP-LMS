# Application map

The app uses Laravel 12, server-rendered Blade, local Bootstrap 5.3.8 assets, MySQL, Eloquent, Laravel sessions, notifications, mail, validation and private filesystem storage.

## Academic model

`academic_years + programs + year_levels + semester → blocks → students`

`teacher + block + subject → teacher_assignments → enrollments`

`TeacherAssignment` is the classroom aggregate used throughout the UI. Student placement automatically enrolls the student in that block's classes. Creating a class automatically enrolls existing block students. Admins can manage explicit enrollments. Student program/year level are normalized through the block relationship.

Lessons, materials, assignments, quizzes, schedules, grades and attendance sessions reference a classroom. Attendance records reference a session/student pair with a database unique constraint. Submission versions and quiz attempt numbers have compound unique constraints. MySQL foreign keys protect academic history; users support soft deletion and suspension.

## Authorization

All portal routes require authentication, active account status and email verification. Administrative routes additionally require the admin role. `Access::classes` scopes reads to the teacher profile or explicit student enrollment. Every class mutation checks the classroom and role again. Submission downloads check student ownership; quiz attempts check ownership; report filters operate inside the authorized query. A `manage-class` gate is also registered for UI/extensions.

`CatalogRequest` validates allowlisted administrative resources. `Catalog` defines those models, field types and validation rules. `AdminController` adds academic consistency and schedule conflict checks. Content forms use a separate allowlist in `ClassContent`; arbitrary table names, model classes, and submitted role fields are never accepted as persistence instructions.

## Assessment rules

- Lessons/materials/assignments are draft or published. Students see published content only.
- Assignments retain all versions. Grading validates point limits, records feedback and updates the gradebook from the latest graded version.
- Quizzes support multiple-choice questions, a configured attempt count, availability dates and a server-side expiry timestamp. Question edits are limited to drafts without attempts. Quizzes with attempts are locked.
- Attempt creation is serialized on the quiz row; finishing is serialized on the attempt row. Duplicate submissions do not change a completed score. The best completed attempt appears in the gradebook.
- Answers are submitted as one form. A timer submits at expiry, but server time is authoritative. This release does not autosave in-progress answers.
- Scores are visible after completion. Correct answers are revealed only after the availability window closes, preventing later-attempt answer disclosure.
- The class gradebook's final percentage sums scores against all listed assessment points. Missing work is zero. The dashboard's recorded average uses only recorded grades and is labelled accordingly. No official university transmutation scale is implied.

## Attendance rules

The default late threshold is 15 minutes, configurable for new sessions. Each session stores its threshold so future settings changes do not reinterpret historical attendance. Provided arrival minutes determine Present versus Late. Teachers can record/edit through seven calendar days after the session; older records require an administrator. Every admin write requires a reason. Every record creation/edit stores before/after JSON, actor and reason in an audit log.

Students may submit one excuse request for an absent record. A permitted teacher/admin approves or rejects it under the same editing window rules. Excused records are excluded from the attendance rate denominator. Empty/fully excused sets display N/A.

## Reports and exports

Attendance supports academic year, semester, program, year level, block, subject, student, status and date filters, plus teacher filtering for admins. Student queries stay limited to their own records even when a different student ID is supplied. Class selection provides student subject filtering. The report includes history, monthly calendar, counts, and per-class rates. Grade, enrollment and student roster reports share the same class scope.

PDF uses Dompdf with remote resource access disabled. Excel output is an actual `.xlsx` OpenXML ZIP workbook; cells use explicit strings so user content cannot become spreadsheet formulas. `ReportExport` generates both formats from the same scoped rows shown on screen.

## Main routes

- `/login`, `/logout`, `/activate/{user}`, `/verify-email`, `/forgot-password`, `/reset-password/{token}`
- `/dashboard`, `/classes`, `/classes/{classroom}`, `/schedule`, `/search`
- `/classes/{classroom}/content/{kind}/create`, edit/store/update/delete equivalents
- `/assignments/{assignment}`, `/assignments/{assignment}/submit`, `/submissions/{submission}/grade`
- `/quizzes/{quiz}`, `/quizzes/{quiz}/questions`, `/quizzes/{quiz}/start`, `/attempts/{attempt}`
- `/attendance/sessions/{session}`, `/attendance/{record}/excuse`, `/excuses/{excuse}`
- `/classes/{classroom}/gradebook`, `/reports/{attendance|grades|students|enrollments}`
- `/announcements`, `/notifications`, `/profile`
- `/admin/manage/{resource}`, `/admin/settings`, `/admin/users/{user}/invite`

Role dashboards also have `/admin/dashboard`, `/teacher/dashboard`, `/student/dashboard` aliases. The shared classroom routes avoid triplicating logic while enforcing authorization at each entry point.
