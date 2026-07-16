# License API — local test rig

PHP API + MySQL, running locally via Docker Compose. Mirrors what Hostinger
serves in production exactly (see [MIGRATION.md](MIGRATION.md)) — same PHP
files, same schema, just Apache-on-shared-hosting swapped for `php -S` in a
container. Nothing about the client code, `Tarang2p1.exe`, or the API's own
logic changes when you move from here to there; only the URL you point at.

## Run it

```
cp .env.example .env
docker compose up --build
```

API is now at `http://localhost:8080`. MySQL is exposed on `localhost:3307`
(user `license_app`, db `licensing`) if you want to inspect it with a GUI
client. `db/init.sql` creates three tables — `licenses`, `activations`
(which machine holds which license), and `license_events` (an audit trail
of created/extended/revoked/reactivated) — and seeds two test licenses:
- `TEST-1234-5678-9ABC` — active, 2 activation seats, expires 2027-12-31
- `TEST-EXPIRED-0001` — active status but expiry_date in the past (tests the expiry-rejection path)

## How a real customer actually uses this

They never touch this repo at all. You generate a key on the admin page
(below), send them the key plus `Tarang2p1.exe`, and it does everything
else itself: computes their machine's fingerprint locally (no script for
them to run), starts the container, and the container calls `/activate`
then `/validate` against whatever `-licenseapi` URL you gave them before
it unlocks the lab content. See `tarang2p1-go`'s `fingerprint.go` /
`NVR/entrypoint.sh`'s license-gate block for exactly how that's wired.

## Manual testing (without Tarang2p1.exe)

For testing the API directly, or for a different Dockerized product that
needs the same fingerprint-lock pattern:

```powershell
cd client
$fp = .\Get-Fingerprint.ps1
python client\license_check.py activate TEST-1234-5678-9ABC $fp
python client\license_check.py validate TEST-1234-5678-9ABC $fp
```

`Get-Fingerprint.ps1` must run on the actual host (not in a container) —
a container can't read the host's `MachineGuid`/BIOS UUID on its own. For
`Tarang2p1.exe` this doesn't matter since the fingerprint is computed by
the *launcher* before Docker even starts, then passed in as an env var
(see above) — this manual step is only for testing or for a different
product's own installer/launcher to replicate the same pattern.

Try a different fingerprint value to see `activation_limit_reached` once
you exceed `max_activations`, and `TEST-EXPIRED-0001` to see
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
needed — this is the page to hand off if someone else is issuing licenses,
and the only thing you'd actually use day-to-day once this is deployed to
Hostinger (`https://license-api.yourdomain.com/admin.html`). It shows the
generated key plus the exact `Tarang2p1.exe` command to give the customer,
and a second form to look up any key: status (active/revoked/expired),
seats used, which machines have activated it, and its full history
(created/extended/revoked events).

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

**CLI** (same underlying logic, via `AdminController` — needs SSH access to
wherever the API is hosted, which the web UI above doesn't):
```
docker compose exec api php bin/generate-license.php --email=customer@example.com --expires=2027-12-31
```

`--seats` defaults to `1` — that's what makes a key work on only one
machine (a second machine's fingerprint gets `activation_limit_reached`
once the single seat is taken). Pass `--seats=N` to issue a multi-seat key
instead, and `--prefix=XXXX` to change the key's prefix (default `TDP1`).

`ADMIN_TOKEN` in `.env`/`config.php` protects every `/admin/*` endpoint —
leave it blank to disable the admin page entirely (all return `403`). It
travels as a plain `X-Admin-Token` header, so this only actually protects
anything once the API is served over HTTPS (see MIGRATION.md) — don't rely
on it over plain HTTP beyond local testing.

## How the pieces fit together

- **`license_key`** — random, generated server-side (`AdminController`),
  no relation to any hardware. Decided by you: who, how many seats, how
  long.
- **`fingerprint`** — `SHA256(MachineGuid + BIOS UUID)`, computed entirely
  on the customer's own machine. Decided by their PC: which machine.
- **`activations` table** — the only place the two actually connect: one
  row per `(license_id, fingerprint)`, written the first time a key is
  activated. `max_activations` is enforced by counting rows here, not by
  anything encoded in the key itself. `last_seen_at` updates on every
  `/validate`, so the lookup page can show whether a machine is still
  actively checking in.
- **`license_events` table** — a log of what happened to a license and
  when (created/extended/revoked/reactivated), shown on the lookup page.

## Moving to Hostinger later

See [MIGRATION.md](MIGRATION.md) for the full step-by-step (database
setup in hPanel, document root, `.htaccess` rewrite rules, credentials
via `config.php`, SSL, and pointing the client at production).
