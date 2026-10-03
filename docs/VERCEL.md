# Deploying this Laravel project on Vercel

This deployment uses the community `vercel-php` runtime pinned to PHP 8.3. The single function at `api/index.php` loads the existing `public/index.php`; `vercel.json` serves only the bundled public assets directly and sends all other requests through Laravel. The Laravel routes and authentication remain unchanged.

Import the GitHub repository into Vercel with the repository root as the project root and **Other** as the framework preset. Do not set a custom Output Directory or replace the configured function with a static site build. The PHP runtime installs the locked Composer dependencies; `vendor/` is intentionally excluded from the upload. The runtime owns its internal PHP listener, so this function deployment does not run `artisan serve` or bind a Laravel process to `PORT`. Do not add a start command or a `PORT` environment variable.

## Production environment variables

Add these in Vercel Project Settings → Environment Variables. Use separate values for Production and Preview when they point to separate databases. Generate a fresh `APP_KEY` with `php artisan key:generate --show` on a trusted machine; never commit it or rotate it casually after users have data.

```dotenv
APP_NAME="UEP LMS"
APP_ENV=production
APP_KEY=base64:<generated-key-material>
APP_DEBUG=false
APP_URL=https://<your-deployment-domain>
APP_TIMEZONE=Asia/Manila
LOG_CHANNEL=stderr
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=<external-mysql-host>
DB_PORT=3306
DB_DATABASE=<database-name>
DB_USERNAME=<database-user>
DB_PASSWORD=<database-password>

SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CACHE_STORE=database
QUEUE_CONNECTION=database

FILESYSTEM_DISK=local
LMS_PRIVATE_FILESYSTEM=s3
AWS_ACCESS_KEY_ID=<private-object-storage-key>
AWS_SECRET_ACCESS_KEY=<private-object-storage-secret>
AWS_DEFAULT_REGION=<storage-region>
AWS_BUCKET=<private-bucket-name>
AWS_ENDPOINT=<s3-compatible-endpoint-if-required>
AWS_USE_PATH_STYLE_ENDPOINT=false

MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=<gmail-address>
MAIL_PASSWORD=<gmail-app-password>
MAIL_FROM_ADDRESS=<verified-sender-address>
MAIL_FROM_NAME="UEP LMS"
```

Leave `AWS_ENDPOINT` unset for AWS S3; set it for an S3-compatible provider and use that provider's path-style setting. Keep the bucket private. The `local` disk name is retained so existing upload and download code continues to work; `LMS_PRIVATE_FILESYSTEM=s3` changes only that disk's deployment configuration. Laravel's separate `public` disk is not used for private LMS uploads. If the external MySQL provider requires TLS, configure its connection and CA according to the provider's instructions; the existing MySQL configuration already supports `DB_URL` and `MYSQL_ATTR_SSL_CA`.

The wrapper directs Laravel's compiled views, manifests, and temporary storage to Vercel's writable temporary directory. This is **not persistent storage**. Files written there may disappear at any time. Configure the private bucket before allowing profile photos, class materials, and assignment submissions. Copy any existing files from `storage/app/private` to the bucket with the same relative keys before switching an existing database to the new deployment. Keep `APP_KEY` stable across deployments so encrypted sessions and other encrypted values remain readable.

## Database and scheduler

Provision the external MySQL database and run `php artisan migrate --force` against it from a trusted machine or deployment job before opening the site. Run `php artisan lms:create-admin` there for the first account. Do not run migrations or seed demo accounts inside a Vercel request or build. Preview deployments should use a separate database if they must be writable.

The current scheduler in `routes/console.php` runs deadline reminders hourly and finalizes expired quiz attempts every minute. Vercel cron invokes HTTP endpoints, while this application has only Artisan scheduled commands. Keep an external worker/cron host running `php artisan schedule:run` every minute against the same production database and cache. No new public scheduler route is added. Expired quiz attempts are also enforced when users open or submit them, but background finalization still needs the worker.

## Platform limits

School Year Setup CSV invitations require an external supervised `php artisan queue:work database --queue=default --tries=3 --timeout=30` worker running the same source revision and production MySQL, APP_KEY, APP_URL and SMTP environment. Vercel cannot run a persistent Artisan queue worker. The setup transaction queues invitations in the existing jobs table; it reports queued messages, not delivered email. Existing individual Resend invitation remains synchronous. No new migrations are needed. For very large bulk attendance/grade forms, configure PHP `max_input_vars` to at least 3500; incomplete row/field payloads are rejected without partial saves.

Vercel Functions have a 4.5 MB request and response payload limit. This application currently allows class file uploads up to 20 MB and private downloads through Laravel. Such large uploads and downloads cannot be fully supported by the present request flow on Vercel; they require direct-to-storage upload/download work or hosting Laravel on a conventional PHP server. This deployment preparation deliberately does not alter those existing features. Large PDF/XLSX exports can encounter the same response limit. Function duration and memory limits may also affect large reports; choose a region near MySQL.

After connecting and setting variables, verify HTTPS login, all three roles, a small private upload/download, SMTP invitation/reset delivery, the external scheduler, and report exports on the actual Vercel deployment. The local test suite and configuration checks cannot prove the third-party PHP runtime, MySQL provider, storage provider, or Gmail connectivity until those services are configured.
