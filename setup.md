# Setup Guide

## 1. Local XAMPP setup

Copy the project folder to:

```text
C:\xampp\htdocs\summit_craft_leadership
```

Start Apache and MySQL in the XAMPP control panel.

Open phpMyAdmin and import:

```text
database/schema.sql
```

Open:

```text
http://localhost/summit_craft_leadership/
```

Admin portal:

```text
http://localhost/summit_craft_leadership/admin.html
```

Local default admin login:

```text
Username: admin
Password: ChangeMeNow!
```

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

## 2. Configure local settings

The local starter file is:

```text
api/config.php
```

For XAMPP, the default database settings are usually:

```php
'host' => 'localhost',
'name' => 'summitcraft_leadership',
'username' => 'root',
'password' => '',
```

## 3. Change the admin password

Generate a new password hash:

```bash
php -r "echo password_hash('your-new-secure-password', PASSWORD_DEFAULT), PHP_EOL;"
```

Put the generated hash into `api/config.php`:

```php
'password_hash' => 'paste-generated-hash-here',
'using_default_password' => false,
```

## 4. Change the app secret

Generate a new app secret:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Put it into:

```php
'security' => [
    'app_secret' => 'paste-generated-secret-here',
]
```

Use a different secret locally and on the live server.

## 5. Live server settings

On the live server, set:

```php
'app' => [
    'debug' => false,
]
```

If the site uses HTTPS, set:

```php
'cookie_secure' => true,
```

Use the live MySQL database name, username and password.

## 6. Git deployment

`api/config.php` is intentionally ignored by Git. Keep using:

```text
api/config.example.php
```

as the template, then manually create `api/config.php` on each environment.

## 7. Test checklist before live

- Homepage loads.
- Contact enquiry saves successfully.
- Booking page loads sessions.
- Booking reduces available spaces.
- Payment page shows the generated reference.
- Payment information downloads correctly.
- Admin login works.
- Admin can add/edit/deactivate a session.
- Admin can mark a booking as paid.
- CSV export works.
- `api/config.php` is not in Git.
- HTTPS is active on the live site.

## Styling and branding notes

The site has been redesigned to match the SummitCraft Leadership logo style. Most visual changes are contained in `css/brand.css`, so future colour, spacing and card changes can be made there without changing the PHP backend.

The navigation and footer now use the supplied logo files from `assets/img/`.
