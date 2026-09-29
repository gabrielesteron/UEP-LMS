# UEP-style Block-Based LMS

A working university learning portal with Admin, Teacher and Student roles, built with Laravel 12, PHP, MySQL, Blade and Bootstrap. This is an independent academic project, not an official University of Eastern Philippines product or policy implementation.

## Included

- Academic years, programs, year levels, semester-based blocks, student/teacher profiles, subjects, teacher assignments, automatic block enrollment and schedules with conflict checks.
- Admin-created account invitations, signed email activation, verification, password reset/change, throttled login, suspension and profile photos.
- Teacher class workspaces, published lessons, private materials, versioned assignment submissions, grading/feedback, timed MCQ quizzes and gradebooks.
- Attendance sessions, configurable lateness, duplicate protection, seven-day teacher edit window, reasoned admin corrections, audit history and excuse requests.
- School/program/block/class announcements, Laravel notifications, deadline reminders, search, filtered reports, attendance calendars, real PDF and Excel exports.
- Fictional demo seed data, automated feature tests, deployment documentation, bundled frontend assets and locked Composer dependencies.

## Requirements

PHP 8.2+, Composer 2 and MySQL 8.0+. PHP must include PDO MySQL, mbstring, fileinfo, DOM/XML, curl, openssl and zip. Enable PDO SQLite for the test suite. See [deployment details](docs/DEPLOYMENT.md) for the full list. Tested with PHP 8.2.12 and MySQL 8.4.3.

For Vercel, see the [Vercel deployment guide](docs/VERCEL.md) for environment variables, private object storage, scheduler requirements, and platform limits.

No Node build, React, Vue, Inertia or Livewire is required. Bootstrap CSS/JS are already in `public/vendor`. `package.json` records the frontend dependency for maintainers.

## Local installation

Clone this repository, open a terminal inside `UEP-LMS`, then run:

```sh
composer install
```

Copy the environment example:

```powershell
copy .env.example .env
```

On macOS/Linux use `cp .env.example .env` instead. Then:

```sh
php artisan key:generate
```

Create an empty MySQL database named `uep_lms` (utf8mb4), and configure these `.env` values for your local MySQL account:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=uep_lms
DB_USERNAME=your_local_database_user
DB_PASSWORD=your_local_database_password
APP_URL=http://localhost:8000
APP_TIMEZONE=Asia/Manila
```

Then run:

```sh
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

Open **http://localhost:8000**. XAMPP or Laragon may provide PHP/MySQL locally; the built-in Laravel server is enough for development. Point any Apache virtual host to `public`, not the project root. Windows may require Developer Mode or elevated rights for `storage:link`; private LMS files do not depend on this symlink.

Set PHP `upload_max_filesize=20M` and `post_max_size=24M` to use the full application upload allowance. Restart PHP after changing its configuration.

## Demo credentials

All demo accounts use **`CampusDemo!2026`**.

| Role | Email |
| --- | --- |
| Admin | `admin@example.com` |
| Teacher | `teacher@example.com` |
| Student | `student@example.com` |

Additional teachers: `teacher2@example.com`, `teacher3@example.com`. Additional students: `student2@example.com` through `student8@example.com`. All names are fictional and prefixed “Demo”.

The seed creates BSIT blocks 3J and 3K, six subjects per block, eight students, three teachers, lessons, downloadable study guides, assignments, quizzes, schedules, attendance, grades and announcements. The primary teacher manages PF102/CC106 in 3J plus assigned subjects in 3K. The primary student belongs to 3J. Demo accounts are already verified. Seeding is blocked in production.

## First workflows to try

1. **Student:** Open My classes → PF102, read a lesson, download the study guide, submit Activity 1, and take the Week 1 knowledge check. Review your grades, attendance calendar and notifications.
2. **Teacher:** Open My blocks & subjects → BSIT 3J/PF102. Create content, grade the submission, or create an attendance session and record the roster. To build a quiz, save a draft, add questions, then publish it from Edit settings.
3. **Admin:** Manage academic records from the sidebar. Create a student/teacher account, then its academic profile. Student placement and new teacher assignments automatically create enrollments. Configure a schedule and try an overlapping time to see validation. Export a filtered report.

## Email and account activation

The default `MAIL_MAILER=log` writes development emails to `storage/logs/laravel.log`. It does **not** send to an inbox. Open a generated activation/reset URL from that log for local testing. Configure real SMTP before inviting real users online:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=your-provider-host
MAIL_PORT=587
MAIL_USERNAME=your-provider-username
MAIL_PASSWORD=your-provider-password
MAIL_FROM_ADDRESS=noreply@your-domain
MAIL_FROM_NAME="Campus LMS"
```

Use your provider's TLS settings; implicit TLS typically uses `MAIL_SCHEME=smtps` and port 465. Set `APP_URL` to your actual HTTPS address. Run `php artisan config:clear` after environment changes.

An admin creates the account; a single-use signed invitation lets the recipient verify their email and choose a password. Links expire after 60 minutes. The admin can resend an unused invitation. Public users cannot register or choose a role. Administrator accounts are created through `php artisan lms:create-admin`.

## Background tasks

For local reminders and automatic expiry finalization:

```sh
php artisan schedule:work
```

Production should run `php artisan schedule:run` every minute. `lms:deadlines` sends one in-app reminder within 24 hours of an unsubmitted assignment's deadline. `lms:expire-quizzes` finalizes expired attempts. Quiz deadlines are always checked on the server even without a scheduler. Email and database notifications are synchronous; no queue worker is required by the implemented workflows.

## Tests

Run from the project root:

```sh
composer test
```

Or:

```sh
php artisan test
php vendor/phpunit/phpunit/phpunit
```

`phpunit.xml` uses an isolated in-memory SQLite database and a test mail transport. Tests cover role/class restrictions, authentication and signed activation, password reset, admin forms/CRUD, enrollment, schedule conflicts, uploads, submission history, grading bounds, quiz scoring/expiry/duplicates, attendance rules/audits/excuses, report ownership and exports. See [TESTING.md](docs/TESTING.md) for the final verification record and MySQL test instructions.

## Hosting

See [DEPLOYMENT.md](docs/DEPLOYMENT.md) for SMTP, MySQL, nginx/Apache, permissions, upload limits, cron, caching and production setup. Use `APP_ENV=production`, `APP_DEBUG=false` and `SESSION_SECURE_COOKIE=true` behind HTTPS. Never deploy the demonstration accounts to a live student system. Use migrations without demo seeding and create your first admin interactively.

## Design decisions

See [ARCHITECTURE.md](docs/ARCHITECTURE.md) for the data model, routes and rules. Student program/year level are inherited from the assigned block. Academic records with dependencies are protected from deletion; suspend accounts to revoke access. Quiz answers are submitted in one form without autosave; expired unsubmitted attempts score zero. Attendance defaults and point-based grade percentages are project rules, not official university policies. Exports produce real PDF and `.xlsx` files from the authorized, filtered report data.

The repository includes source, tests, migrations, seeders, documentation, the Composer lock file and local frontend assets. Run `composer install` to restore dependencies. Local secrets, databases, logs, caches and uploads are excluded. Setup generates a new installation key and fresh demo data.
