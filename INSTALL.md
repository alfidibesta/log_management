# Installing this site on your own PC

The site is plain PHP + MySQL — no framework, no composer, no build step.

## What you need

| Requirement | Why |
|---|---|
| PHP **8.1 or newer** with `pdo_mysql` | runs the site |
| PHP **zip** extension (`ZipArchive`) | reading/writing the Excel files |
| MySQL 5.7+/8+ or MariaDB 10.3+ | the database |

XAMPP, WAMP, Laragon (Windows) and MAMP (macOS) all ship PHP with both
extensions already enabled — that is the easiest route.

## Folder layout

```
public/        ← the only folder that should be reachable from the web
config.php     ← database settings (edit this one)
src/           ← code + schema (never web-reachable)
README.md
INSTALL.md
```

---

## Option A — Windows, with XAMPP (easiest)

1. **Install XAMPP** from https://www.apachefriends.org and start **Apache**
   and **MySQL** in the XAMPP Control Panel.
2. **Extract the zip**, then copy the `log-management` folder into
   `C:\xampp\htdocs\` so you have `C:\xampp\htdocs\log-management\public\`.
3. **Create the database**: open http://localhost/phpmyadmin →
   *New* → database name `log_management`, collation
   `utf8mb4_unicode_ci` → *Create*. (Nothing else to do — every table is
   created automatically on the first page load.)
4. **Tell the app about the database**: edit
   `C:\xampp\htdocs\log-management\config.php` and replace the four `getenv(...)`
   lines with plain values:

   ```php
   'host' => '127.0.0.1',
   'port' => '3306',
   'name' => 'log_management',
   'user' => 'root',
   'pass' => '',          // XAMPP's MySQL root has no password by default
   ```

   (On a real server you would rather use the `LOG_DB_*` environment
   variables than edit this file — see Option C.)
5. **Open the site**: http://localhost/log-management/public/
   The login page detects that no accounts exist and turns into a
   **create-admin-account** form — pick your own username and password.
   Five categories and a week of sample gate entries are seeded once so the
   dashboard isn't empty.
6. Sign in and start recording on **Input**, checking on **Verify**.

> Keep MySQL's root password empty only on a throwaway local machine. Set one
> with phpMyAdmin → *Privileges* → change root password, and put it in
> `config.php`.

## Option B — macOS

With Homebrew:

```sh
brew install php mysql
brew services start mysql

mysql -uroot -e "CREATE DATABASE log_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

cd log-management
# edit config.php: user 'root', pass = your MySQL root password, host 127.0.0.1
php -S localhost:8000 -t public
```

Then open http://localhost:8000 — first run creates your admin account.
(MAMP works the same as XAMPP: put the folder in `MAMP/htdocs/`, create the
database in phpMyAdmin, edit `config.php`.)

## Option C — a real server / shared hosting (cPanel)

1. Create a MySQL database and user in cPanel and note their names.
2. Upload the **contents of `public/`** into `public_html/` (or a
   subfolder), and upload `config.php` + `src/` **one level above** the web
   root, keeping the `../src` paths intact:

   ```
   public_html/
     login.php  index.php  input.php  verify.php …   ← contents of public/
     ../config.php
     ../src/
   ```
3. Set the credentials — either edit `config.php` as above, or keep the file
   untouched and set environment variables, e.g. in `.htaccess`:

   ```apache
   SetEnv LOG_DB_HOST localhost
   SetEnv LOG_DB_NAME youruser_log_management
   SetEnv LOG_DB_USER youruser_app
   SetEnv LOG_DB_PASS 'your-password'
   ```
4. Visit the site once: the schema creates itself, then create the admin
   account.

---

## After installing

- **First screen:** the login page → *create your first admin account*.
- **Menu:** Dashboard · Input · Verify · Summary for everyone; Data Log ·
  Categories · Users for admins only (personnel get a 403 on those).
- **Roles:** create gate-officer accounts in *Users* with the `personnel`
  role — they can record and verify, but not delete, export or change users.
- **Excel:** export/import lives on *Data Log* (admin). If upload fails with
  a zip error, enable the extension: `extension=zip` in `php.ini`, restart
  Apache.
- **Auto-refresh:** open *Input* in one window and *Verify* in another; an
  entry recorded in one appears in the other within ~3 seconds.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `SQLSTATE[HY000] [2002] Connection refused` | MySQL isn't running, or `host`/`port` in `config.php` is wrong |
| `Access denied for user …` | wrong user/password in `config.php` |
| Blank page / `Class PDO_mysql not found` | enable `extension=pdo_mysql` in `php.ini` |
| Excel import says the zip extension is missing | enable `extension=zip` in `php.ini` |
| `419` on save | session/CSRF token expired — reload the page and try again |
| Tables missing | just load any page once; `src/db.php` creates the schema |

## Security notes

- Only `public/` should be web-reachable; `config.php` and `src/` must stay
  outside the document root on a public server.
- All POSTs carry a CSRF token, passwords are stored with `password_hash()`,
  and every page behind `login.php` is guarded server-side.
- On a public host, set a real MySQL password and use HTTPS.
