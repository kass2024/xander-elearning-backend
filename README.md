# Xander Learning Hub — Backend API

Laravel API for **Xander Learning Hub** (courses, students, Zoom, Stripe).

## Database migrations (automated schema)

All table changes live in `database/migrations/`. **No manual SQL is required.**

### Automatic (recommended)

Set in `.env`:

```env
AUTO_MIGRATE=true
```

When the API starts, pending migrations run automatically (`AppServiceProvider`).

### Manual / production deploy

```bash
php artisan migrate --force
```

Or call the API after deploy:

```bash
curl -X POST "https://your-api/api/admin/system/migrate" \
  -H "X-Migrate-Token: YOUR_MIGRATE_TOKEN"
```

Set `MIGRATE_TOKEN` in production `.env` to protect the migrate endpoint.

### Health check

```bash
curl "http://localhost:8000/api/admin/system/health"
```

Returns database connectivity, pending migrations, and per-table column verification.

Migrations use `Schema::hasTable()` / `Schema::hasColumn()` guards so they are safe on fresh installs and existing production databases.

| Area | Migration files |
|------|-----------------|
| Users (role, phone, status, avatar) | `0001_*`, `2025_11_17_*`, `2026_01_23_180000_*` |
| Students (approval, profile) | `2025_11_05_*`, `2025_11_07_*`, `2025_11_23_*` |
| Instructors (users.role + status Pending) | `2025_11_17_000600_*`, `2026_01_23_180000_*` |
| Courses & enrollments | `2025_11_17_*`, `2025_11_18_*`, `2025_11_28_*` |
| Stripe payments | `2026_06_09_120100_*` |
| Full hub sync (idempotent) | `2026_06_11_120000_sync_elearning_hub_schema.php` |
| Meeting registrations | `2026_01_23_*`, `2026_06_09_120200_*` |
| Live Zoom cohort | `2026_06_09_120000_*` |
| Zoom / Stripe env | `.env` — `ZOOM_*`, `STRIPE_*` |

## Deploy checklist

1. Copy `.env.example` → `.env` and set DB, Zoom, Stripe, mail keys  
2. `composer install --no-dev --optimize-autoloader`  
3. `php artisan migrate --force`  
4. `php artisan config:cache`  

Do **not** commit `.env`, `vendor/`, or `storage/` (see `.gitignore`).

---

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
