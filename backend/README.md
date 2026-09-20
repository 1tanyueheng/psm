# PSM Backend (Laravel 11)

REST API for the PSM Management System.

## Local development

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

## Docker

```bash
docker compose up -d
docker compose exec app php artisan migrate --seed
```

The API is served at `http://localhost:8000/api`.

## Scheduled jobs

Milestone reminders and overdue scans run via the scheduler:

```bash
php artisan schedule:work     # local
# or in production, run cron:
* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1
```
