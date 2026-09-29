# Verification record

Verified on 27 September 2026.

| Check | Result |
| --- | --- |
| PHP runtime | 8.2.12 |
| Laravel framework | 12.69.2 (locked in Composer) |
| SQLite feature suite | 38 tests, 291 assertions, all passing |
| MySQL feature suite | MySQL 8.4.3: 38 tests, 291 assertions, all passing |
| Application PHP syntax | 74 files checked, zero syntax errors |
| Composer manifest | Valid |
| Composer dependency audit | No known advisories reported at build time |
| Laravel production caches | Configuration, events, routes and Blade views compiled successfully |
| Browser checks | Admin/student/teacher login, desktop dashboard, teacher attendance, admin academic-year dates, student timed quiz and score, mobile reports/menu |
| Browser console | No errors in the inspected final pages |
| Mobile width | Attendance report checked at 390px viewport; no document horizontal overflow |

MySQL **8.4.3** was available and tested. MySQL 8.2 was not installed and was not separately verified. The PHP runtime was **8.2.12**; PHP and MySQL version numbers are distinct.

## Coverage

The feature suite exercises login/logout/invalid login/throttling, suspension and deleted accounts, verification signatures, single-use activation, password reset/change, profile field restrictions, all admin catalog list/create/edit views, program/block/subject CRUD, duplicate email, prohibited role escalation, automatic enrollment, schedule conflicts, all role dashboards and common pages, class/content IDOR denial, published/draft lessons, private downloads, invalid and oversized uploads, late/versioned submissions, grading bounds and ownership, quiz scoring and answer secrecy, expiry, duplicate/multiple attempts, empty/unavailable quizzes, question creation/publishing/locking, attendance duplicates and 14/15-minute thresholds, configurable thresholds, seven-day editing boundaries, old/future attendance, admin reasons, audit entries, excuse review, N/A attendance, report ownership and pagination, PDF/XLSX exports for all report types, audience-scoped announcements, idempotent reminders, and CSRF enforcement outside the test environment.

The browser demonstration completed a three-question quiz and displayed **15 / 15 points**. These demonstration interactions are not included as pre-consumed quiz attempts in the ZIP; fresh seeding creates unused attempts.

## Reproduce

```sh
composer install
composer test
```

The default `phpunit.xml` uses SQLite `:memory:`. Tests do not use the normal cached application configuration. The base test class refuses to proceed against any database except `:memory:` or a database whose name ends with `_test`.

For MySQL, create a **dedicated disposable** database such as `uep_lms_test`, copy `phpunit.xml` to `phpunit.mysql.xml`, and change/add only its DB environment entries:

```xml
<env name="DB_CONNECTION" value="mysql"/>
<env name="DB_DATABASE" value="uep_lms_test"/>
<env name="DB_HOST" value="127.0.0.1"/>
<env name="DB_PORT" value="3306"/>
<env name="DB_USERNAME" value="your_test_database_user"/>
<env name="DB_PASSWORD" value="your_test_database_password"/>
```

Then run `php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml`. The test suite migrates and rolls back its database for each feature test. Never point it at real academic data. Keep test credentials out of version control.

## Verification limits

External SMTP delivery, a public HTTPS deployment, load testing, and MySQL 8.2 were not tested. Mail generation and activation/reset behavior were tested using Laravel's test notification/mail facilities. Configure SMTP and run a real delivery check on your own hosting before distributing accounts. UI verification sampled the principal workflows and responsive breakpoints; automated rendering checks cover the application views listed above. The application has not undergone an independent penetration test.
