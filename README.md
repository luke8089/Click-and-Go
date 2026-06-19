# Click E-Commerce System

A Laravel-based e-commerce platform built for product sales, customer self-service, and administrative management.

> **Current version: 2.2.0** — see [Version History](#version-history) and [CHANGELOG.md](CHANGELOG.md) for progress.

## Overview

This repository contains a Laravel 12 application with a complete shopping experience, including:

- Public storefront with product catalog, categories, search, filters, and product suggestions.
- Shopping cart with quantity updates, item removal, coupon application, and guest checkout support.
- Checkout flow supporting M-Pesa and bank transfer payments.
- Order management for customers and administrators.
- Customer account area for order history, receipts, profile updates, password changes, wishlist, and saved items.
- Admin dashboard for managing products, categories, orders, customers, coupons, careers, testimonials, reviews, and basic site settings.
- Careers and job application module with email notifications.
- Contact form with anti-spam protection and admin alerts.
- SEO-supporting sitemap generation.

## Key Features

- Product browsing with category and attribute filtering
- Full cart lifecycle: add, update, remove, clear, and item count
- Coupon validation and discounts with free shipping rules
- Checkout with guest or authenticated purchase flow
- M-Pesa payment integration and callback verification
- Bank transfer option with admin confirmation support
- Buy Now quick-purchase flow
- Wishlist and testimonial submission
- User authentication, registration, email verification, password reset, and Google OAuth
- Admin-only routes protected by authentication and `admin` middleware
- Multi-environment configuration using `.env`

## Architecture

- `app/Http/Controllers` — request handling for storefront, checkout, cart, auth, admin, careers, and payments
- `app/Models` — Eloquent models for products, categories, orders, coupons, users, reviews, testimonials, and more
- `app/Services` — payment integration logic, including M-Pesa STK and status query
- `app/Http/Middleware` — security, MPesa callback verification, checkout gating, and admin protection
- `resources/views` — Blade templates for public pages, account area, and admin interface
- `routes/web.php` — application routing for public, auth, account, checkout, and admin endpoints
- `config/mpesa.php` — M-Pesa configuration and sandbox/live environment switching

## Requirements

- PHP 8.2+
- Composer
- Node.js + npm
- MySQL or compatible database
- `npm` build tools (Vite, Tailwind)

## Setup

1. Copy environment configuration:

```bash
cp .env.example .env
```

2. Install PHP dependencies:

```bash
composer install
```

3. Install JavaScript dependencies:

```bash
npm install
```

4. Generate application key:

```bash
php artisan key:generate
```

5. Configure database and M-Pesa settings in `.env`:

- `DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `MPESA_API_ENV` (`sandbox` or `live`)
- `MPESA_CONSUMER_KEY`, `MPESA_CONSUMER_SECRET`
- `MPESA_BUSINESS_SHORT_CODE`, `MPESA_PASSKEY`
- `MPESA_CALLBACK_URL`
- `MPESA_VERIFY_IP`

6. Run migrations:

```bash
php artisan migrate
```

7. Build the frontend assets:

```bash
npm run build
```

8. Serve the application locally:

```bash
php artisan serve
```

## Development

For local development with Vite and Laravel queue support, use:

```bash
npm run dev
```

Or use the provided Composer script:

```bash
composer run dev
```

## Testing

Run the Laravel test suite with:

```bash
php artisan test
```

## Useful Commands

- `php artisan migrate` — run database migrations
- `php artisan db:seed` — seed the database
- `php artisan route:list` — list registered routes
- `php artisan config:clear` — clear config cache
- `npm run build` — compile production assets

## Admin Access

The admin panel is served under `/admin` and requires a user with the `admin` role.

## Notes

- M-Pesa callbacks are verified through a middleware that allows only Safaricom IP ranges in production.
- Contact submissions include rate limiting and honeypot spam protection.
- Coupon and free shipping logic are configurable through the application settings.

## Version History

Releases are tagged in Git so progress can be reviewed at each milestone. Check out any tag with `git checkout <tag>`.

| Version | Highlights |
| ------- | ---------- |
| `v2.2.0` | Resolved README merge conflicts, added project CHANGELOG, exposed app version in `config/app.php`. |
| `v2.1`   | README updates and merge-conflict resolution. |
| `v2.0.0` | Added `.htaccess` configuration and `VERSION` file. |
| `v1.2`   | Documentation and storefront refinements. |
| `v1.1`   | Early storefront and admin iteration. |

The full, detailed history is maintained in [CHANGELOG.md](CHANGELOG.md).
