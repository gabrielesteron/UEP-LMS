# Deployment guide

## Requirements

- PHP 8.2 or later compatible with the included Composer lock file. Tested with PHP 8.2.12.
- PHP extensions: ctype, curl, dom, fileinfo, filter, hash, mbstring, openssl, pcre, PDO, pdo_mysql, session, tokenizer, xml, xmlreader, xmlwriter, zip. Enable pdo_sqlite for the default test suite.
- MySQL 8.0+ (tested on MySQL 8.4.3); InnoDB and utf8mb4.
- Composer 2. No Node server or frontend build is required; Bootstrap is bundled locally.
- A web server with HTTPS, PHP-FPM or an equivalent PHP handler, and a scheduler.

## Fresh production deployment

1. Extract the project outside the web document root. Point the virtual host **only** to `UEP-LMS/public`.
2. Run `composer install --no-dev --optimize-autoloader` on the server to validate platform requirements and prepare dependencies. The ZIP also includes vendor dependencies for convenience.
3. Copy `.env.example` to `.env` and set `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`, `APP_URL=https://your-lms.example`, `SESSION_SECURE_COOKIE=true`, and your actual database/SMTP values.
4. Run `php artisan key:generate` once on a new installation. Preserve this key during upgrades. Set `APP_TIMEZONE` to the campus timezone; the default is `Asia/Manila`.
5. Create a dedicated MySQL database and user, with permissions only on that database. Use that account in `.env`.
6. Run `php artisan migrate --force` and `php artisan lms:create-admin`. The latter prompts for a trusted administrator name, email, and hidden password. It marks the trusted console-created administrator as verified. Do not seed demo accounts in production; the seeder refuses production mode.
7. Allow the PHP service account to write `storage/` and `bootstrap/cache/`. Keep the remainder of the application read-only to the service account when practical.
8. Run `php artisan storage:link`. This creates a link for the public disk only. Educational uploads and profile photos use `storage/app/private` and never require a public symlink.
9. Run `php artisan optimize` after configuration is final. After future environment changes, run `php artisan optimize:clear`, then `php artisan optimize` again.
10. Configure the scheduler, test account invitation/reset delivery, and verify HTTPS login before distributing access.

## SMTP

The example uses `MAIL_MAILER=log` and `LOG_LEVEL=debug`, which writes messages to `storage/logs/laravel.log` for development only. Keep debug-level logging enabled when using the log mailer, because its generated messages are logged at debug level. To send real email:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-smtp-username
MAIL_PASSWORD=your-smtp-password
MAIL_FROM_ADDRESS=noreply@your-campus-domain
MAIL_FROM_NAME="Campus LMS"
```

Use your provider's exact host, port and TLS requirements. `smtp` on 587 negotiates STARTTLS when supported; use `smtps` on 465 when your provider requires implicit TLS. Configure domain SPF/DKIM as instructed by your email provider. Set `APP_URL` correctly before sending links. Clear cached configuration after changes.

Account invitations verify the email address and set the first password in one signed, single-use activation flow. Invitation/reset tokens expire after 60 minutes; admins can resend invitations. No public registration exists. Suspended users cannot regain access with a password reset. Individual account invitations and password reset emails use Laravel Mail synchronously. School Year Setup CSV invitations use the existing database queue to keep large imports out of a synchronous SMTP loop. Run a supervised `php artisan queue:work database --queue=default --tries=3 --timeout=30` worker using the same production configuration; restart it on releases. Individual Resend invitation remains available if a worker is not running. Assignment, grade, announcement, quiz, and deadline notifications are stored in Laravel's database notification table.

Real SMTP delivery depends on your hosting network and provider; the automated tests verify notification generation, valid/invalid links, and password/account changes using a test mail transport. They do not claim delivery to an external mailbox.

## Scheduler

Run once per minute on Linux:

```cron
* * * * * cd /var/www/uep-lms && php artisan schedule:run >> /dev/null 2>&1
```

On Windows, use Task Scheduler to run `php artisan schedule:run` every minute with the project folder as its working directory. During local development, `php artisan schedule:work` is convenient.

The scheduler sends one in-app reminder per unsubmitted assignment/user within 24 hours of its due time and finalizes expired quiz attempts. Expiry is also enforced when a quiz is opened or submitted; it does not depend on JavaScript or the scheduler. Unsubmitted answers receive no credit when an attempt expires. Set an accurate server clock.

## Upload limits

Set `upload_max_filesize=20M`, `post_max_size=24M`, and a suitable request timeout in the PHP configuration. Restart PHP afterward. For nginx, set `client_max_body_size 24m;`. Laravel accepts approved educational document types up to 20 MB and images for profile photos up to 2 MB. Files receive randomized private paths; downloads check role/class membership and submission ownership. Maintain backups of both MySQL and `storage/app/private` together.

## nginx example

Adapt paths, PHP socket and TLS certificate directives to your server. The following belongs inside an HTTPS `server` block:

```nginx
root /var/www/uep-lms/public;
index index.php;
client_max_body_size 24m;

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location = /index.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
}

location ~ \.php$ { return 404; }
location ~ /\.(?!well-known).* { deny all; }
```

For Apache/XAMPP, set `DocumentRoot` to the project's `public` folder, enable `mod_rewrite`, and allow `.htaccess` overrides for that folder. Do not serve the entire project directory. XAMPP is optional for local development and is not a production requirement.

## Operations

- Use suspension to revoke user access while preserving academic history. Related academic records use restrictive foreign keys to prevent destructive deletion.
- Back up the database, private uploads and the protected `.env` separately from source releases. Test restores.
- Keep PHP/Laravel dependencies updated within supported versions and run `composer audit` during releases.
- Avoid overlapping academic identities: create a new block for a new academic period, and a new class for a new block rather than repurposing a class containing work.
- A student's program/year level comes from the block, which prevents conflicting duplicate placement fields. Moving a student changes current enrollment; prior records remain stored for administrators and assigned teachers.
- Reports currently load filtered records before exporting. For very large datasets, use a bounded date range and plan a queued/streaming reporting extension.
- The attendance and grade policies are configurable project rules, not official UEP regulations. The project is not an official UEP product and includes no institutional logos or personal records.
