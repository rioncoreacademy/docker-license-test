# Local License API test rig (no Hostinger)

Mirrors the production architecture (PHP API + MySQL) but runs entirely on
your machine via Docker Compose. Swap the base URL to your Hostinger domain
later; nothing else about the client or API code changes.

## Run it

```
cp .env.example .env
docker compose up --build
```

API is now at `http://localhost:8080`. MySQL is exposed on `localhost:3307`
(user `license_app`, db `licensing`) if you want to inspect it with a GUI
client.

Two test licenses are seeded (see [db/init.sql](db/init.sql)):
- `TEST-1234-5678-9ABC` — active, 2 activation seats, expires 2027-12-31
- `TEST-EXPIRED-0001` — active status but expiry_date in the past (tests the expiry-rejection path)

## Get a fingerprint (run on the Windows host, not in a container)

```powershell
cd client
$fp = .\Get-Fingerprint.ps1
```

## Test activate / validate

```powershell
python client\license_check.py activate TEST-1234-5678-9ABC $fp
python client\license_check.py validate TEST-1234-5678-9ABC $fp
```

Try it again with a different fingerprint value to see `activation_limit_reached`
once you exceed `max_activations`, and with `TEST-EXPIRED-0001` to see
`license_expired`.

## Endpoints

| Endpoint             | Method | Body/Query                              | Notes                                   |
|-----------------------|--------|------------------------------------------|------------------------------------------|
| `/activate`           | POST   | `{license_key, fingerprint}`             | Idempotent — re-activating the same machine returns `already_activated: true` |
| `/validate`           | POST   | `{license_key, fingerprint}`             | Called on every app startup |
| `/admin/generate`     | POST   | `{email, expires, seats?, fingerprint?}` | Requires `X-Admin-Token` — see below |
| `/admin/lookup`       | GET    | `?key=...`                               | Requires `X-Admin-Token` |
| `/admin/extend`       | POST   | `{license_key, expires, seats?}`         | Requires `X-Admin-Token` — updates the *same* license, doesn't create a new one |
| `/admin/revoke`       | POST   | `{license_key}`                          | Requires `X-Admin-Token` |
| `/admin/reactivate`   | POST   | `{license_key}`                          | Requires `X-Admin-Token` |

## Generate, extend, revoke a license key

**Web UI** — open `http://localhost:8080/admin.html`, paste your
`ADMIN_TOKEN` (set in `.env`), and use the form. No Docker/CLI knowledge
needed — this is the page to hand off if someone else is issuing licenses.
It shows the generated key plus the exact `Tarang2p1.exe` command to give
the customer, and a second form to look up any key: status (active/revoked/
expired), seats used, which machines have activated it, and its full
history (created/extended/revoked events).

**Renewing an existing customer**: use **Extend** on the lookup result
(updates that license's expiry/seats in place) rather than generating a
brand-new key — a fresh `Generate` creates a fully independent license with
no link back to the old one, so repeated renewals via Generate leave you
with an ever-growing pile of unrelated keys per customer instead of one
that's kept current.

**Catching a "new" customer who's actually a repeat one**: the Generate
form has an optional fingerprint field. Email alone is trivial to fake
(just use a different address); the fingerprint is the machine's actual
hardware ID and can't be. If you ask for it before issuing a key (customer
runs `Get-Fingerprint.ps1` / `get-fingerprint.sh` and sends you the output),
generating flags any existing license — active or not — tied to either that
email *or* that fingerprint, so a customer can't dodge an unrenewed
key by just using a new email address. The lookup page does the same
cross-check retroactively for any key that's already been activated
(`related_by_fingerprint`), no fingerprint prompt needed at generate time.

**CLI** (same underlying logic, via `AdminController`):
```
docker compose exec api php bin/generate-license.php --email=customer@example.com --expires=2027-12-31
```

`--seats` defaults to `1` — that's what makes a key work on only one
machine (a second machine's fingerprint gets `activation_limit_reached`
once the single seat is taken). Pass `--seats=N` to issue a multi-seat key
instead, and `--prefix=XXXX` to change the key's prefix (default `TDP1`).

`ADMIN_TOKEN` in `.env`/`config.php` protects every `/admin/*` endpoint —
leave it blank to disable the admin page entirely (all return `403`).

## Moving to Hostinger later

See [MIGRATION.md](MIGRATION.md) for the full step-by-step (database
setup in hPanel, document root, `.htaccess` rewrite rules, credentials
via `config.php`, SSL, and pointing the client at production).

## Known gap to close before shipping

A container can't read the host's `MachineGuid` or BIOS UUID on its own —
`Get-Fingerprint.ps1` must run on the host and the resulting hash gets
passed into `docker run` as an environment variable (or read by a
host-side launcher script/installer before it starts the container). If
your actual product needs to fingerprint from *inside* the container, the
architecture needs a different anchor (e.g. a value written to a
host-mounted volume by an installer).
