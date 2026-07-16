# Migrating the License API from local Docker to Hostinger

Only `license-api/` and `db/init.sql` move to Hostinger. Nothing else does:
the `db` and `api` containers in [docker-compose.yml](docker-compose.yml)
were a local stand-in for "PHP API (Hostinger) + MySQL" — Hostinger shared
hosting doesn't run Docker, so on the way there this becomes plain PHP
files served by Apache, talking to a normal MySQL database. The client
scripts in `client/` and the customer's actual Dockerized product are
unaffected; only the URL they call changes.

## 1. Create the database in hPanel

1. hPanel → **Databases → MySQL Databases** → create a new database and
   user (Hostinger prefixes both with your account ID, e.g.
   `u123456789_licensing` / `u123456789_license_app`). Note the password.
2. Open **phpMyAdmin** for that database and run [db/init.sql](db/init.sql)
   under **Import** (or paste it into the SQL tab).
3. Delete the two `TEST-*` seed rows from `licenses` afterward — they're
   for local testing only:
   ```sql
   DELETE FROM licenses WHERE license_key IN ('TEST-1234-5678-9ABC', 'TEST-EXPIRED-0001');
   ```

## 2. Create the subdomain / domain and set its document root

1. hPanel → **Websites → Subdomains**, create something like
   `license-api.yourdomain.com`.
2. Under its **Advanced → Document Root**, point it at
   `license-api/public` (not `license-api/`) inside the folder you'll
   upload to. This keeps `src/`, `config.php`, and `Dockerfile` outside
   the web-reachable path, same as the local layout.
3. hPanel → **SSL** — issue the free SSL certificate for the subdomain
   and enable **Force HTTPS**. The license API must only ever be called
   over HTTPS.

## 3. Upload the code

Via **File Manager** or SFTP, upload the *contents* of `license-api/`
(the `public/`, `src/` folders and `config.php.example`) into the folder
you set as the parent of that document root. Do **not** upload the
`Dockerfile` — it's meaningless on shared hosting and there's no reason
to expose it.

## 4. Configure credentials

1. Copy `license-api/config.php.example` to `license-api/config.php` on
   the server (File Manager → duplicate, then rename — or upload it
   separately since it's gitignored and won't come from your repo).
2. Fill in the `DB_HOST` (usually `localhost` on Hostinger),
   `DB_NAME`, `DB_USER`, `DB_PASS` from step 1.
3. `Database.php` already checks for this file automatically — no other
   code changes needed. Locally it keeps using the `DB_*` environment
   variables from `docker-compose.yml`; the `config.php` path only
   applies where env vars aren't set.

## 5. Check PHP version and extensions

hPanel → **Advanced → PHP Configuration** for the subdomain — select
PHP 8.1+ and confirm `pdo_mysql` is enabled (it is by default on
Hostinger).

## 6. Verify routing

`public/.htaccess` rewrites all requests to `index.php` so `/activate`
and `/validate` resolve correctly under Apache (this replaces the
`php -S` router used by the local Docker container). Confirm
`mod_rewrite` is on — it is by default on Hostinger — by hitting
`https://license-api.yourdomain.com/activate` and confirming you get a
JSON `400 missing_license_key_or_fingerprint` response rather than a 404.

## 7. Point clients at production

Update wherever `LICENSE_API_BASE_URL` is set (env var for
`client/license_check.py`, or wherever your real product's entrypoint
reads it) from `http://localhost:8080` to
`https://license-api.yourdomain.com`.

## 8. Smoke test

```powershell
$env:LICENSE_API_BASE_URL = "https://license-api.yourdomain.com"
$fp = .\client\Get-Fingerprint.ps1
python client\license_check.py activate <a-real-license-key> $fp
python client\license_check.py validate <a-real-license-key> $fp
```

## Ongoing: issuing real licenses

`db/init.sql` only seeds test rows. For real customers, generate a key with
`license-api/bin/generate-license.php` — works the same way locally or on
Hostinger since it's a plain PHP CLI script:

```
php bin/generate-license.php --email=customer@example.com --expires=2027-12-31
```

`--seats` defaults to `1` (single-machine license). See
[README.md](README.md#generate-a-license-key) for the full option list.
Wiring this into an actual purchase flow (Gumroad webhook, etc.) isn't
covered here — ask if you want that built out next.
