# SummitCraft Leadership — PHP/MySQL Business Starter

This version is designed for a usual **XAMPP / PHP / MySQL / HTML / CSS / JavaScript** workflow.

It includes the public website, enquiry capture, session booking, payment-reference generation, bank-transfer payment instructions, CSV exports, and a strengthened administration portal.

## Main features

- Enquiry form saves to MySQL.
- Booking form shows available sessions, available spaces, and the price for the selected programme.
- Bookings generate a unique payment reference.
- Payment instruction page lets participants download/print payment details.
- Admin portal can view enquiries and bookings.
- Admin portal can add/edit/deactivate sessions and set capacity. Each session belongs to The Ascent, The Ridgeline or The Summit.
- Admin portal can set one participant price for each of the three bookable programmes: The Ascent, The Ridgeline and The Summit.
- Admin can update booking status: pending payment, paid, cancelled, attended, refunded.
- CSV export for enquiries and bookings.
- Audit log for admin actions.


## Programme and session model

There are exactly three bookable programmes:

- The Ascent
- The Ridgeline
- The Summit

The admin portal sets one price per participant for each programme. Admin can create multiple sessions for the same programme on different dates, with different locations and capacities. For example, several sessions of **The Ascent** can be available on different days, but they all use the current **The Ascent** price. When a participant books, the price at that time is saved with the booking for payment and records.

## Strengthening added in this version

- PDO prepared statements for database access.
- Password hash based admin login.
- HttpOnly admin session cookie instead of storing auth tokens in localStorage.
- CSRF token checks for admin changes.
- Admin login rate limiting.
- Public form rate limiting.
- Honeypot spam field on enquiry and booking forms.
- Privacy consent checkbox on enquiry and booking forms.
- Hashed IP/browser audit information for public forms and admin activity.
- Security headers and directory listing disabled via `.htaccess`.
- `api/config.php` is excluded from Git.
- Email log table and optional PHP `mail()` starter setting.

## XAMPP local setup

1. Extract this folder into:

```text
C:\xampp\htdocs\summit_craft_leadership
```

2. Start Apache and MySQL in XAMPP.

3. Open phpMyAdmin and import:

```text
database/schema.sql
```

4. Open the site:

```text
http://localhost/summit_craft_leadership/
```

5. Open the admin portal:

```text
http://localhost/summit_craft_leadership/admin.html
```

Default local admin credentials are:

```text
Username: admin
Password: ChangeMeNow!
```

Change these before live use.

## Optional migration from previous prototype

If you already imported the earlier PHP/MySQL prototype database, back up your database first, then run:

```text
database/migrate_previous_php_mysql_to_hardened.sql
```

For a new project/database, use `database/schema.sql` only.

If you already imported the previous business-starter version and need to add the three-programme pricing table, run:

```text
database/migrate_business_starter_to_programme_prices.sql
```

If you already ran the earlier programme-pricing migration that included extra service prices, run this cleanup migration once:

```text
database/migrate_programme_prices_to_three_core_programmes.sql
```

## Important Git notes

Commit this file:

```text
api/config.example.php
```

Do **not** commit this file:

```text
api/config.php
```

The `.gitignore` file already excludes `api/config.php`. Create/update `api/config.php` separately on each machine and on the live server.

## Before publishing live

Update `api/config.php` on the live server:

- Set `app.debug` to `false`.
- Generate a new `security.app_secret`.
- Set `security.cookie_secure` to `true` if the live site uses HTTPS.
- Generate a new admin password hash.
- Set `admin.using_default_password` to `false`.
- Update database credentials.
- Update the payment details in the admin portal.
- Configure email properly if you want automatic confirmations.

Generate a new app secret:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Generate a password hash:

```bash
php -r "echo password_hash('your-new-secure-password', PASSWORD_DEFAULT), PHP_EOL;"
```

## Email confirmations

The code currently writes email messages to the `email_logs` table. This is deliberate for the initial version so you can test safely.

For live use, use a proper SMTP provider and PHPMailer or similar. The starter config also has an optional PHP `mail()` mode, but SMTP is preferred for real business delivery.

## Suggested live deployment workflow

1. Develop locally in XAMPP.
2. Test database import and admin login locally.
3. Push the project to your Git repository without `api/config.php`.
4. Pull the repository onto the live server.
5. Create the live `api/config.php` manually on the live server.
6. Import `database/schema.sql` into the live MySQL database.
7. Confirm HTTPS is enabled.
8. Login to admin and change programme prices, payment details, and session details.
9. Submit a test enquiry and test booking.
10. Confirm CSV exports and booking status updates work.

## Known limitations of this initial version

This is a strengthened starter version, not a fully audited enterprise platform. Before heavy business use, consider adding:

- Full SMTP email integration.
- Two-factor authentication for admin.
- A proper admin-user table if multiple staff need access.
- Scheduled cleanup of expired admin sessions/rate-limit rows.
- Automated backups.
- A staging environment separate from live.
- Formal legal review of the privacy statement and terms.

## Visual redesign update

This package includes a refreshed SummitCraft Leadership visual design based on the supplied black/gold logo assets:

- Premium black, gold and warm cream colour palette.
- Logo-based header and footer branding.
- Redesigned homepage hero, programme cards, upcoming sessions, benefits and testimonial sections.
- Restyled booking, enquiry, admin and payment screens while preserving the PHP/MySQL functionality.

The included logo files are stored in `assets/img/`:

- `summitcraft-logo-wide.png`
- `summitcraft-logo-square.png`

The main visual styling is in `css/brand.css`.

## Homepage image slideshow

The homepage hero now uses a rotating background slideshow built from the supplied outdoor images. The optimised web images are stored in:

`assets/img/hero-slides/`

The slideshow markup is in `index.html` and the visual overlay/transition styling is in `css/brand.css`.
