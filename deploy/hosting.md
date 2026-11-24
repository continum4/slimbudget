# Shared hosting (panel with SSH)

You need PHP 8.4+ with `mbstring`, `openssl`, `pdo_mysql` and `xmlwriter`, and MariaDB 10.6+, MySQL 8.4 or MySQL 5.7 (it works, but it no longer gets security fixes). The PHP of the console can be older than the one of the site: in that case, run the commands with its full path.

1. **Subdomain:** create it in the panel with its document root in `slimbudget/public`, outside any other site. `config/` and `var/` must never be under a public folder.
2. **Certificate:** turn on HTTPS for the subdomain and redirect HTTP to it in the panel. If it has no such option, add this to `public/.htaccess`, after `RewriteEngine On`:
   ```apache
   RewriteCond %{HTTPS} !=on
   RewriteCond %{REQUEST_URI} !^/\.well-known/
   RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
   ```
3. **Database:** create one database and two users in the panel, if it lets you: one with every privilege (`db_admin`, only for migrations) and one with `SELECT, INSERT, UPDATE, DELETE` (`db`, for the app). With only one user, use it in `db`.
4. **Code:**
   ```sh
   git clone https://github.com/continum4/slimbudget.git && cd slimbudget
   composer install --no-dev --optimize-autoloader
   cp config/local.example.php config/local.php
   chmod 600 config/local.php && chmod -R go-rwx var
   ```
   If PHP runs as another user, give that user, and nobody else, access to both.
5. **`config/local.php`:** `'env' => 'production'`, the `https://` URL, the time zone, the database and a new `key`. Keep a copy of the key: without it, nobody with two-step verification can sign in.
6. **Tables and first user:**
   ```sh
   php bin/migrate.php
   php bin/seed.php starter en
   php bin/user.php alex "Alex" admin
   ```
7. **Before sharing the address:** sign in, change the temporary password, turn on two-step verification and save the recovery codes.
8. **Check it from outside:** `/config/local.php`, `/.git/config` and `/composer.json` must give 404, and `/` must lead to the login.

Never load the demo data on a public server: its password is published.

If the panel writes in `public/.htaccess` (cPanel does, to choose the PHP version), run `git update-index --skip-worktree public/.htaccess` so that it does not get in the way of `git pull`.

## Behind a proxy or CDN

Add its addresses to `trusted_proxies`. Otherwise every visitor counts as one address, and anyone could use up your sign-in attempts.

## Backups

Run one daily from the panel's cron, encrypted and stored somewhere else. With [age](https://age-encryption.org), only your own computer can open them:

```sh
mariadb-dump --defaults-extra-file=$HOME/.backup.cnf --single-transaction DATABASE | gzip | age -r age1yourpublickey > $HOME/backups/slimbudget-$(date +\%F).sql.gz.age
```

`~/.backup.cnf` holds the user and password (`chmod 600`). Download the files regularly and try restoring one.

## Locked out

`php bin/user.php <username> "<Name>" <role>` sets a temporary password, turns off two-step verification and clears the failed attempts of that user.

Failed sign-ins go to `var/log/auth.log`, one per line with the address. PHP errors go to `var/log/php.log`.
