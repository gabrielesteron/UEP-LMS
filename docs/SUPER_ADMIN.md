# Super Admin and role boundaries

The LMS keeps one `users` table, the existing login, invitations, activation,
password reset, middleware and class ownership checks. Roles are the existing
strings `admin`, `teacher`, `student`, plus `super_admin`. No existing account is
automatically promoted, demoted, seeded or given a new password.

## Initial setup

Apply the additive administrative-audit migration using the correct production
connection before deploying this release:

```sh
php artisan migrate --path=database/migrations/2026_10_05_000000_create_administrative_audit_logs.php --force
```

Then deliberately choose an existing active, verified Admin without an academic
Student/Teacher profile and run:

```sh
php artisan lms:promote-super-admin chosen-admin@example.com
```

The command requires confirmation. `--force` is an explicit confirmation for
trusted automation, never a bypass of account eligibility. It preserves the
account's password and remember token and records the promotion. Repeating it
for an existing eligible Super Admin makes no changes. It creates no account.
If no administrator exists on a fresh installation, the existing interactive
`lms:create-admin` command creates one with a hidden, validated password first.
Do not use demo seeding in production. Vercel does not provide a persistent PHP
console: run these commands from a trusted checkout with the same production
database configuration, keeping credentials in ignored local configuration.

## Authority and navigation

| Role | System administration | Academic operations | Teaching / learning |
| --- | --- | --- | --- |
| Super Admin | Allowed | Existing Admin authority | School-wide oversight |
| Admin | Denied | Existing setup and academic management | School-wide oversight |
| Teacher | Denied | Own assigned classes | Existing teaching, grading, attendance |
| Student | Denied | Own enrolled classes | Existing learning, submissions, progress |

Super Admin sees System Administration, Academic Management, Learning & Activities,
Reports and Account. Admin sees Academic Setup, People, Classes, Learning &
Activities, Reports and Account. Teacher and Student keep their existing three
core modules and Announcements/Profile links. Overview remains shared. The
wizard and academic relationship/enrollment behavior are unchanged.

Existing `/admin/manage/users` and invitation URLs remain intact but require
Super Admin authorization on both reads and writes. `/super-admin/admins` is a
forced Admin-only view of the same account system. The existing `/admin/settings`
attendance threshold remains academic configuration, accessible to academic
administrators; it is distinct from the new System Settings page.

## Account safety

- Role is excluded from ordinary model mass assignment and profile requests.
  Authorized account changes assign it explicitly; trusted test factories and
  seeders use Laravel's explicit unguarded fixture mechanism.
- Account changes, archives, restores and console promotions use transactions
  and a consistent lock order for privileged accounts. The actor's active,
  verified Super Admin authority is re-read for each account mutation.
- A Super Admin cannot deactivate, demote, archive or change the email of their
  own account. The last active, verified Super Admin is protected. Self role,
  status and email inputs are read-only, and no self-archive button is shown.
- Linked Student/Teacher profiles prevent role changes. Archiving soft-deletes
  the user and suspends access while retaining all academic IDs. Restoring does
  not reactivate access; verified accounts return suspended, unverified accounts
  inactive. A separate reviewed status change is required for access.
- New accounts always start inactive with an unknown random hashed password and
  use the existing signed activation invitation. Email changes require fresh
  activation. An unverified account cannot be manually activated.
- Browser confirmations supplement backend protections for sensitive changes.
  Existing CSRF, throttling, verification and active-account checks remain.

## Settings, features and audit

The existing `settings` table stores only `lms_name`, `institution_name` and
`lms_show_advanced_features` for the new pages. No environment-editing interface
is provided. Absent values preserve the existing configuration; advanced tools
remain hidden by default. This flag retains its original presentation-only
meaning and does not replace route authorization.

Roles & Permissions documents the fixed role/class-scope matrix and links to
authorized role assignment. There is no new granular permission framework.
System Information displays only application name, Laravel/PHP versions,
environment, database driver and debug state. It never dumps configuration.

The only new table, `administrative_audit_logs`, records account creation/edits,
role/status changes, archive/restore, invitations, activation and system
settings/feature changes. Metadata is explicitly allowlisted and does not store
passwords, mail/database credentials or activation/reset tokens. Console actions
have a null actor and a source marker; account actions identify the operator.
Attendance history remains in its existing separate system. The audit page is
read-only. Rollback refuses to drop populated history outside explicitly guarded
disposable test databases; export and review history before any deliberate rollback.

## Verification

Run `php artisan test`, `php artisan route:list` and `php artisan view:cache`.
The suite uses guarded disposable databases and covers the four-role access
matrix, all system page/write protections, account/profile preservation,
invitation activation and hashing, lockout protections, promotion, settings,
audit transaction rollback, navigation links/states and CSRF, alongside the
existing complete academic, curriculum, teacher and student regression suite.

MySQL-compatible test teardown exposed an existing curriculum rollback ordering
bug: InnoDB can use the curriculum index for a foreign key, so the constraints
must be removed before that index. Only this empty-curriculum rollback order was
corrected; the forward migration, production schema and populated-data rollback
guard remain unchanged.

### Release verification — 5 October 2026

- Complete suite: **149 tests / 3,532 assertions passed** on SQLite and on
  MariaDB 10.4.32 through Laravel's MySQL driver, using disposable test databases.
- All **73 existing routes** retained their methods, URLs, names and actions;
  **10** protected Super Admin routes were added.
- PHP syntax, Composer manifest/platform checks, Blade compilation and route
  compilation passed. Account/permission tests include all four roles and
  invitation activation with normal Laravel password hashing.
- Desktop and 390px mobile browser checks covered all four role navigations,
  account pages, teacher class quick actions, student progress/schedule,
  sidebar open/close and sign-in/sign-out.
- Only the additive audit migration was applied to local and Railway databases.
  Before/after fingerprints of **33 existing data tables** were unchanged;
  production still had **zero Super Admins**. No promotion, demo seeding,
  production reset or academic-data deletion was performed.
- Cross-engine verification also corrected existing test assumptions about SQL
  identifier quoting, unordered row comparisons and stored decimal formatting;
  academic calculations and curriculum import behavior were not changed.

Production Super Admin screens require the deliberate initial promotion above.
All four roles were exercised in isolated browser/test fixtures; no production
account was promoted solely for verification.
