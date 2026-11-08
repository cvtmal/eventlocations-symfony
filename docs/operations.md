# Production operations

Runbook for deine-eventlocation.ch on its production host (D26): an **Infomaniak VPS** in
Switzerland running Debian 13, nginx and php-fpm 8.5; **Neon** Postgres 18 in `aws-eu-central-1`
(Frankfurt); **Infomaniak SMTP** for mail; the system **crontab**; **Sentry** in the EU region.
`deineeventlocation.ch` redirects to `deine-eventlocation.ch` (E7). Deploys are the owner's alone.

> **Status: dry run.** This runbook was written in cycle F15 before any server existed. No step
> below has been run against production, nothing here describes a live state, and every command is
> a plan the owner checks off on the first setup. Fix this file where reality differs.

The site has one operator, so each section says what to run, what a good result looks like and what
to do when it goes wrong. GitHub Actions builds every release (the server has no Composer, Node or
Sass), the server only unpacks and switches.

Conventions: `$PROD_SSH` is the owner's own login on the VPS, `deploy@<vps>`, set in the shell and
never in a file. `<root>` is `/var/www/deine-eventlocation.ch`. Console commands by hand run in
`<root>/current` as the `deploy` user: `cd <root>/current && php bin/console …`.

## Layout

```
/var/www/deine-eventlocation.ch/          owned by deploy:deploy
  current  -> releases/<sha>              what nginx, php-fpm and cron run
  previous -> releases/<sha>              what rollback goes back to
  releases/<sha>/                         one unpacked tarball each; the newest 3 plus both links stay
    .env.local        -> shared/.env.local
    .env.prod.local                       SENTRY_RELEASE=<sha>, written by the deploy
    var/cache/                            this release's compiled container, never shared
    var/{log,sessions,share,media,stats,lock,import-staging} -> shared/var/…
    public/media      -> shared/public/media
  shared/.env.local                       the secrets, chmod 600, written once by hand
  shared/var/…                            sessions, HTTP cache store and cache pools, media originals,
                                          statistics spool, limiter locks, upload staging, logs
  shared/public/media/                    generated image variants and PDFs
  deploy-gate                             the forced command of the GitHub key, copied by hand
  .deploy/                                the gate's lock and stage
/var/www/letsencrypt/                     certbot's webroot for renewals
```

The pieces in the repository: `bin/deploy` (install, purge, check, rollback), `bin/deploy-gate`,
`deploy/nginx/deine-eventlocation.ch.conf`, `deploy/php-fpm/deine-eventlocation.conf`,
`deploy/crontab.txt`, `deploy/env.local.example`, `deploy/authorized_keys.example`,
`deploy/probe.sh`, `public/.user.ini` (PHP's per-request limits, shipped with each release).
`make deploy-test` runs the scripts against a throwaway tree and the nginx file in a real nginx in
front of php-fpm; `make release-check` boots the tarball with `APP_ENV=prod`.

## First-time setup

Once, in this order. Steps 1 to 4 need root (`sudo`); everything after runs as `deploy`.

### 1. VPS and DNS

- Order an Infomaniak VPS in Switzerland with Debian 13, at least 2 GB RAM and 20 GB disk, and put the
  owner's SSH key on it. Disable password logins for SSH (`PasswordAuthentication no`).
- Point `A` (and `AAAA` if the VPS has IPv6) of all four names at the VPS:
  `deine-eventlocation.ch`, `www.deine-eventlocation.ch`, `deineeventlocation.ch`,
  `www.deineeventlocation.ch`.
- Allow only 22, 80 and 443 in (Infomaniak's firewall in the manager, or `nftables`).

### 2. Packages and time zone

```sh
apt update && apt full-upgrade
timedatectl set-timezone Europe/Zurich        # deploy/crontab.txt is written in Zurich time
apt install nginx certbot unattended-upgrades curl ca-certificates openssl rsync restic
# PHP 8.5 from packages.sury.org (Debian itself ships an older PHP)
curl -sSLo /tmp/sury.deb https://packages.sury.org/debsuryorg-archive-keyring.deb && dpkg -i /tmp/sury.deb
echo 'deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ trixie main' \
  > /etc/apt/sources.list.d/php.list
apt update
apt install php8.5-fpm php8.5-cli php8.5-pgsql php8.5-intl php8.5-gd php8.5-zip php8.5-curl php8.5-mbstring php8.5-xml
update-alternatives --set php /usr/bin/php8.5
```

`php -v` must say 8.5. OPcache is built into PHP 8.5. `restic version` must say 0.18 or newer (Debian 13
ships 0.18.0; the media backup needs its `--skip-if-unchanged`).

### 3. The deploy user and the tree

```sh
adduser --disabled-password --gecos '' deploy
install -d -o deploy -g deploy -m 755 /var/www/deine-eventlocation.ch
install -d -o deploy -g deploy -m 755 /var/www/deine-eventlocation.ch/releases /var/www/deine-eventlocation.ch/shared
install -d -m 755 /var/www/letsencrypt
```

nginx (`www-data`) needs to read `current/public`, so directories stay world-readable; the secrets
file is the one exception (step 6). Put the owner's public key into `~deploy/.ssh/authorized_keys`
(mode 600, directory 700), so `$PROD_SSH` works. Then copy the setup files from a checkout on the Mac,
`scp -r deploy "$PROD_SSH:"`; the root commands in step 4 run in `~deploy`.

### 4. php-fpm, nginx and the certificate

```sh
cp deploy/php-fpm/deine-eventlocation.conf /etc/php/8.5/fpm/pool.d/
systemctl reload php8.5-fpm
```

The pool runs as `deploy` on `/run/php/deine-eventlocation.sock`. The certificate comes first,
through a temporary port-80-only server block, because the real file names a certificate that does
not exist yet:

```sh
cat > /etc/nginx/sites-available/acme <<'CONF'
server {
    listen 80;
    listen [::]:80;
    server_name deine-eventlocation.ch www.deine-eventlocation.ch deineeventlocation.ch www.deineeventlocation.ch;
    location /.well-known/acme-challenge/ { root /var/www/letsencrypt; }
}
CONF
rm -f /etc/nginx/sites-enabled/default
ln -s /etc/nginx/sites-available/acme /etc/nginx/sites-enabled/acme && nginx -t && systemctl reload nginx
certbot certonly --webroot -w /var/www/letsencrypt --cert-name deine-eventlocation.ch \
  -d deine-eventlocation.ch -d www.deine-eventlocation.ch -d deineeventlocation.ch -d www.deineeventlocation.ch
rm /etc/nginx/sites-enabled/acme
cp deploy/nginx/deine-eventlocation.ch.conf /etc/nginx/sites-available/deine-eventlocation.ch
ln -s /etc/nginx/sites-available/deine-eventlocation.ch /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
echo 'deploy-hook = systemctl reload nginx' >> /etc/letsencrypt/cli.ini
```

Until the first deploy every page is a 404 or 502, which is expected. Renewals run through
certbot's own timer and the webroot that the port-80 block keeps open.

**Logs.** nginx logs to `/var/log/nginx/`. Keep 14 days, as the privacy page says: in
`/etc/logrotate.d/nginx` set `daily` and `rotate 13` (the live file plus 13 rotated ones).

### 5. Probe

Copy `deploy/probe.sh` to the server and run it as `deploy` with a Neon test DSN in `~/probe.env`
(the file's header says how; delete it afterwards):

```sh
sh probe.sh | tee probe-cli.txt
```

Check 1 must say 8.5, check 2 lists `rsync` (media sync and backup), check 3 lists `pdo_pgsql`, check 4 says `AVIF: yes`, check 7 says port 5432
open, check 8 connects with channel binding and libpq 14 or newer, check 9 shows Zurich time, check 11
shows `mail.infomaniak.com:587` open with STARTTLS, check 13 says `works`.

### 6. `.env.local`

On the Mac, `cp deploy/env.local.example env.local` outside the checkout and fill it (see
[`.env.local`](#envlocal) for each variable), then:

```sh
scp env.local "$PROD_SSH:/var/www/deine-eventlocation.ch/shared/.env.local"
ssh "$PROD_SSH" chmod 600 /var/www/deine-eventlocation.ch/shared/.env.local
```

The pool runs as `deploy`, so a mode of 600 is enough for the web too.

### 7. Deploy key and gate

On the Mac, make a key that only GitHub holds: `ssh-keygen -t ed25519 -N '' -f deploy_key`. Then:

```sh
scp bin/deploy-gate "$PROD_SSH:/var/www/deine-eventlocation.ch/deploy-gate"
ssh "$PROD_SSH" chmod 700 /var/www/deine-eventlocation.ch/deploy-gate
```

Append the line of `deploy/authorized_keys.example`, with the public key of `deploy_key.pub`, to
`~deploy/.ssh/authorized_keys`. Test it: `ssh -i deploy_key -o IdentitiesOnly=yes "$PROD_SSH" whoami` must answer
`deploy-gate: refused: …` with exit 2 and no shell.

### 8. GitHub

- **Environment `production`** (Settings -> Environments): add the owner as required reviewer and
  allow only `main`. Secrets: `DEPLOY_SSH_KEY` (the private key file), `DEPLOY_KNOWN_HOSTS` (the output
  of `ssh-keyscan -t ed25519 <vps>`, checked against the fingerprint the VPS shows in its console),
  `DEPLOY_SSH_USER` (`deploy`), `DEPLOY_SSH_HOST` (the VPS's name or address). Delete `deploy_key`
  from the Mac afterwards.
- **Repository variable `DEPLOY_ENABLED`** = `true` (Settings -> Variables). Until it exists, every
  job of the Deploy workflow is skipped; set it to anything else to switch deploys off again.

### 9. First deploy, crontab, first admin

1. Actions -> Deploy -> Run workflow on `main`, action `deploy`. The smoke tests at the end need DNS
   and the certificate; before DNS points at the VPS check it directly (`-k` skips the certificate
   check): `curl -kI --resolve deine-eventlocation.ch:443:<vps ip> https://deine-eventlocation.ch/health`.
2. Crontab, as `deploy`: put `MAILTO=` to the operator's address into `deploy/crontab.txt` (the
   `__OPS_MAIL__` placeholder), then `crontab deploy/crontab.txt` and `crontab -l`. The server needs
   a mail transport for cron mail (`apt install msmtp-mta` with the Infomaniak mailbox), or set
   `MAILTO=""` and rely on the Sentry cron monitor in [Monitoring](#monitoring).
3. The media backup: [Media backup](#media-backup), "Setup" (bucket, keys, `init`, a first run).
4. The first staff account: `cd <root>/current && php bin/console app:admin:create-user <e-mail> --role=admin`
   (it asks for the password twice), then log in at `/admin` and enrol the second factor.
5. Content: [Content](#content). Then add the Sentry monitors and the backup schedule.

## Deploy, purge, rollback

All three run from **Actions -> Deploy -> Run workflow**, on `main`; the input `action` chooses.
The workflow never runs on its own: it has no push or schedule trigger, every job is skipped unless
`DEPLOY_ENABLED` is `true`, and the `production` environment waits for the owner's approval.

**`deploy`** refuses unless CI passed on that commit. It builds the tarball in CI and pipes it
through the gate. On the server:

1. The gate takes a lock, unpacks the tarball into `<root>/.deploy/stage` and runs the stage's own
   `bin/deploy install <sha>`.
2. `bin/deploy` checks the tarball and that `shared/.env.local` exists, moves the stage to
   `releases/<sha>`, links the shared paths and writes `.env.prod.local`.
3. In the new release: `app:prod:check-env`, migrations, `cache:warmup`, `app:geo:import-towns`. The
   old release serves all along. Migrations must be additive, because the old release (and a later
   rollback) runs on the new schema.
4. `current` switches to the new release in one rename, `previous` to the old one. nginx passes the
   resolved path to php-fpm (`$realpath_root`), so the next request runs the new code and a running
   one finishes on the old; no php-fpm reload is needed.
5. `app:http-cache:clear`, then releases beyond the newest three are deleted (never `current` or
   `previous`).

A failure before step 4 deletes the new directory again and leaves the live site as it was.

The workflow then smoke-tests `/health`, the security headers and gzip on the listing, the HTTP
cache, the sitemap and `robots.txt`, the redirects of `www`, the second domain and `http`, the admin
login's `no-store`, a media file, and finally `check` on the server. (The listing path in the smoke
tests, `/eventlokale`, follows the routes: change it in `deploy.yaml` and `bin/warm-cache`
when a cycle renames the listing.)

**`purge`** empties the HTTP cache.

**`rollback`** switches `current` to `previous` and `previous` to the release it left, then empties
the HTTP cache; a second rollback goes forward again. Migrations are not undone: the older code runs
on the newer schema, which works because migrations are additive. Without the workflow, the same
over SSH: `ssh "$PROD_SSH" SSH_ORIGINAL_COMMAND=rollback sh /var/www/deine-eventlocation.ch/deploy-gate` (through the gate, so it takes the deploy lock). If data
itself is wrong, see [Backups and restore](#backups-and-restore).

### When a deploy fails

| The log says | State of the site | What to do |
|---|---|---|
| `..., nothing was changed` or `..., the live release is unchanged` | Old release, untouched | Fix the cause (usually `.env.local`, the guard names the variable), deploy again |
| `the console in ... does not boot` | Old release, untouched | The log withholds the console's output because it can quote a secret. Over SSH: `cd <root>/current && php bin/console about`. On the first deploy compare `shared/.env.local` with `deploy/env.local.example` |
| `the migration failed, the live release is unchanged` | Old code. Migrations before the failing one stay applied | Read the error, fix on `main`, deploy again |
| `<sha> is live already` | Unchanged | Nothing to do; a new commit is a new sha |
| `<sha> is the previous release, use rollback` | Unchanged | Run the `rollback` action instead |
| `the release is live but the HTTP cache could not be cleared` | New release live, old pages cached | Run the `purge` action |
| `another deploy is running` | A deploy or rollback is running, or one died | If none is running: `rm -r <root>/.deploy/.lock` |
| Green deploy, but every URL is 502 | php-fpm is down or the socket differs | `systemctl status php8.5-fpm`; the socket in the pool file and in nginx's `fastcgi_pass` must match |
| Green deploy, but one page answers 500 | That page is broken | Run `rollback`, fix on `main`, deploy again |

`sh <root>/current/bin/deploy check` prints `OK`, the live sha, the previous sha, the number of
releases, the PHP version and the free disk.

## `.env.local`

`deploy/env.local.example` is the template; the file is `<root>/shared/.env.local` and every release
links to it. A deploy never writes it, and `bin/deploy` changes nothing live while the file fails
`app:prod:check-env` (it prints variable names, never values).

| Variable | Rule |
|---|---|
| `APP_ENV`, `APP_DEBUG` | `prod` and `0` |
| `APP_SECRET` | At least 32 characters |
| `DEFAULT_URI` | Exactly `https://deine-eventlocation.ch` |
| `DATABASE_URL` | Neon's direct string, see below |
| `PGCHANNELBINDING` | `require`, unless the probe shows it cannot connect |
| `MAILER_DSN` | Infomaniak SMTP as in the template, never `null://` or Mailpit |
| `MAILER_FROM` | `deine-eventlocation.ch <hallo@deine-eventlocation.ch>` |
| `INQUIRY_RECIPIENT_OVERRIDE` | **Must stay empty.** Any value sends every venue's inquiries and every CRM mail to that one address. |
| `ADMIN_TOTP_KEY` | Base64 of 32 bytes, different from the dev key in `.env` |
| `ADMIN_DEV_AUTOLOGIN` | Must stay empty |
| `MEDIA_ROOT` | `/var/www/deine-eventlocation.ch/shared/var/media`, absolute, in `shared/` so every release sees it |
| `MEDIA_MAX_PIXELS`, `MEDIA_MAX_PORTRAIT_RATIO` | `20000000` and `2`. Raise the pixels only together with `memory_limit` in `public/.user.ini`. |
| `GOOGLE_MAPS_EMBED_KEY`, `GOOGLE_MAPS_JS_KEY`, `GOOGLE_MAPS_MAP_ID`, `GOOGLE_PLACES_API_KEY` | Optional; empty means no request goes to Google and the link to Google Maps remains. Restrict each key to its API and the referrers `https://deine-eventlocation.ch/*` and `https://www.deine-eventlocation.ch/*`. `GOOGLE_MAPS_MAP_ID` must not be `DEMO_MAP_ID` once the JS key is set. After a key change run `sh bin/prod-import purge`. |
| `GOOGLE_RATING_ENABLED` | `0` (the default) until the owner has checked the terms (D48, see [Google rating and photos](#google-rating-and-photos)). `1` shows the Google rating on venue pages with a place ID that staff did not hide: with `GOOGLE_MAPS_JS_KEY` Google's element, else only the link to the reviews; `/datenschutz` then explains it. `0`: no rating, no request to Google, no rating paragraph on `/datenschutz`; the place IDs and the CMS card "Google-Bewertung" stay. After a change run `sh bin/prod-import purge`. |
| `GOOGLE_PLACES_PHOTOS_KEY` | Empty until the owner has checked the terms (D48, see [Google rating and photos](#google-rating-and-photos)). Optional server key for the Google photos of a venue without own photos (D12): our server fetches them and passes the bytes on, `no-store`. Restrict it to the Places API (New) and the server's IP address, never a referrer; it never reaches a page. Empty means no photo section, no request and no photo paragraph on `/datenschutz`. The daily cap is the parameter `google_places.daily_requests` (default 300 billed calls); set a quota and a quota alert on the key in the Google Cloud console as well (the app logs the cap only below error level, so production's stderr log does not show it). nginx lets at most 4 requests to these routes run at once (`limit_conn google_photos`); the rest get 503 and the page keeps the placeholder. After setting or emptying the key run `sh bin/prod-import purge`, so cached venue pages and `/datenschutz` follow. |
| `SENTRY_DSN` | Optional. Empty: no error reports. |
| `MEDIA_BACKUP_REPOSITORY`, `MEDIA_BACKUP_PASSWORD`, `MEDIA_BACKUP_ACCESS_KEY_ID`, `MEDIA_BACKUP_SECRET_ACCESS_KEY` | The media backup's bucket, encryption password and S3 keys; read only by `bin/cron-media-backup`, which refuses an empty value or a placeholder. Single quotes. The password also goes into the password manager: without it the backup is unreadable. `MEDIA_BACKUP_REGION` stays empty unless restic cannot find the region. See [Media backup](#media-backup). |
| `SYMFONY_TRUSTED_PROXIES` | Unset: nginx faces the visitor. Only if a proxy is ever put in front; its exact address, never `REMOTE_ADDR` |

`MEDIA_PUBLIC_DIR` is not set in production (default `public/media`). `SENTRY_RELEASE` is not set
either: each deploy writes it into the release's `.env.prod.local`, so a rollback reports the right one.

**`DATABASE_URL`.** Neon's console hands out a string ending in `sslmode=require&channel_binding=require`.
**Delete `channel_binding=require`.** DBAL drops it and `connect_timeout` silently, and
`app:prod:check-env` refuses a URL that names either. The connect timeout is
`PDO::ATTR_TIMEOUT: 10` in `config/packages/doctrine.yaml`; channel binding is the line
`PGCHANNELBINDING=require` in `.env.local`, which `ChannelBindingMiddleware` hands to libpq. The URL
must contain no `%` (Symfony resolves the value), so a password that needs percent-encoding gets
replaced. If the probe shows libpq below 14, use the template's commented `endpoint=` form and drop
`PGCHANNELBINDING`.

**Applying a change.** An edit applies on the next request and needs no deploy. Run `sh <root>/current/bin/deploy
check` afterwards.

**Rotating `APP_SECRET`.** New value from the template's one-liner, in the file and the password
manager, then `check`. Cost: a form open during the change shows the "too fast" error once, and the
inquiry and phone limiter counters start again.

**Losing `ADMIN_TOTP_KEY`.** Without it nobody passes the second login step. Set a new key and run
`php bin/console app:admin:reset-2fa <e-mail>` for every staff member. A forgotten password is
`app:admin:reset-password <e-mail>`.

### Google rating and photos

Both ship dormant (D48): Google's Maps Platform Terms, section 3.2.3(d)(iii), restrict the use of Places content in a
"listings or directory service", which this site is. Switching either on is an **owner step**, taken only after the
owner has checked the current terms (or has Google's written OK) for this use:

1. Rating: set `GOOGLE_RATING_ENABLED=1` in `.env.local` (and `GOOGLE_MAPS_JS_KEY`, if Google's element should
   render rather than only the link). Photos: set `GOOGLE_PLACES_PHOTOS_KEY` as in the table above.
2. Run `sh bin/prod-import purge`: venue pages and `/datenschutz` are cached for a day, and `/datenschutz`
   explains each feature only while it is on, so the cached copies must go.
3. Check a venue with a place ID and no photos of its own, and the section "Karten ... von Google Maps" on
   `/datenschutz`, which now names the feature.

Switching off is the same in reverse (`0` or an empty key, then the purge). The place IDs and the CMS card
"Google-Bewertung" stay in either state.

## Content

The server never imports: the AVIF encoding of a full import would hold the small VPS for a long
time. `bin/prod-import` runs in the local php container against the production database and sends
media up with rsync (into `current/var/media` and `current/public/media`, which are links into
`shared/`). Set
both variables in the shell (`read` keeps the string out of the history) and run
`sh bin/prod-import help` for the commands and the run order:

```sh
read -rs PROD_DATABASE_URL && export PROD_DATABASE_URL
export PROD_SSH=deploy@<vps>
```

Order: first deploy (schema and towns), then `import-dry` until its summary is right, then `all`.
Every import keeps each venue that staff or a manager edited since the last import
(`docs/content-format.md`, "After a CMS edit"). **Never** run `--prune` (the script refuses it),
`make seed` or demo data against production, or keep a Neon string in a file on the Mac. The
sources of new content (managers, the owner's sheet, customers) are the business of cycle F14, which
reshapes this section. `sh bin/warm-cache` requests every public page once; by hand only, never from cron.

## Cron

`deploy/crontab.txt` is the `deploy` user's crontab (`crontab deploy/crontab.txt`, after the
`MAILTO` line names the operator). The jobs call `<root>/current/bin/…`, so a deploy or a rollback
changes what runs without touching the crontab; each script resolves `current` once at its start, so
a switch in the middle of a run does not mix releases. The server's clock is Europe/Zurich (setup
step 2), so the times below are Zurich times. `php` on cron's PATH is 8.5 through
`update-alternatives`; `DEPLOY_PHP=/usr/bin/php8.5` in front of a job overrides it.

| Line | Script | What it does |
|---|---|---|
| `5 0 * * *` | `bin/cron-midnight` | Empties the HTTP cache after listings expire at midnight |
| `2-59/5 * * * *` | `bin/cron-cache-guard` | Empties it only when it is over its size or file limit |
| `15 3 * * *` | `bin/cron-daily` | Inquiry purge, erasure of complainants three years after the decision (D36), purge of the zero-result search counts after 12 months (D46), CRM stage refresh and renewal tasks, paid-term expiry and reminder mails, inquiry backfill, stuck-inquiry report, statistics ingest, cache pool prune, staging, session and limiter-lock sweeps |
| `30 4 * * *` | `bin/cron-media-backup` | The media originals to Object Storage, encrypted, with retention ([Media backup](#media-backup)); no database |

`moderation-erase` (D36) erases the complainant of a report three years after the decision in the
database only. The staff notices ("Meldung: …") in the `site.contact_email` mailbox and the replies
quote the complainant too: delete those older than three years by hand, once a year, so the privacy
text stays true.

The cache jobs share the lock `var/lock/cache-clear.lock` (in `shared/`, so it holds across a
switch). A good night prints nothing, and cron mails a job's output to `MAILTO`, so every mail is
worth reading:

| The mail says | Meaning | What to do |
|---|---|---|
| `cron-daily: inquiries-purge failed`, `moderation-erase`, `search-misses-purge`, or `pool-prune` | The retention promise in the privacy text is at risk; often the database was unreachable | Run `sh <root>/current/bin/cron-daily`; read the error if it fails again |
| `cron-daily: staging-sweep failed`, `sessions-sweep`, `lock-sweep` | A sweep could not delete | Read the step's output in the mail, fix, rerun `sh bin/cron-daily` |
| `cron-daily: crm-backfill failed`, with `<id>: <class>` | One inquiry could not be ingested | The next night retries; if the id returns, check that row. A count-only line is a repaired inquiry, not an error |
| `cron-daily: subscriptions-expire failed`, with `<number>: <reason>` | One venue's lapsed term was not handled; its pages already show Free (the entitlements read the dates), only the listing order waits | The next night retries; if the number returns, open the venue's CRM deal and its terms |
| `cron-daily: subscriptions-remind failed`, with `<id>: <reason>` | One reminder mail could not be prepared; a mail that did not go out is logged as an error and retried the next night | The next night retries; if it repeats, check the mail settings (`MAILER_DSN`) |
| `cron-daily: stats-ingest failed` | Day files not loaded; nothing lost, they stay in `var/stats/spool` | The next night or the Statistik button loads them |
| `<id> venue <n> since <time> UTC` | An inquiry stayed `pending`; the venue may have no mail | Open `/admin/crm/anfragen/<id>` and press Erneut senden |
| `cron-midnight: the cache lock stayed busy for 60 s` | A guard run held the lock | Nothing if the next night is clean |
| `HTTP cache store: ... bytes, ... files.` | The store hit a limit and was emptied | Nothing, unless it repeats daily: raise the limits |
| `cron-media-backup: the backup failed`, with restic's error | No new backup tonight; the last one stays | Read restic's line: `wrong password` or `Access Denied` is `.env.local`, `repository does not exist` is the URL or a missing `init`, a network error retries tomorrow. `already locked` after a crash: `sh bin/cron-media-backup restic unlock`. Rerun `sh <root>/current/bin/cron-media-backup` |
| `cron-media-backup: ... is not set in .env.local`, `... still holds the template's placeholder` | The backup never started | Fill the `MEDIA_BACKUP_*` lines in `shared/.env.local` |
| `cron-media-backup: the retention failed (the backup itself is done)` | Old snapshots were not forgotten | Nothing if the next night is clean; otherwise run `sh bin/cron-media-backup restic check` |

A new nightly job is a console command plus a line in `bin/cron-daily` plus a note under
[Monitoring](#monitoring), and must be idempotent on a second run.

## Statistik

The site counts page views, listing impressions, contact clicks, Merkliste adds and inquiries
itself, with no cookie and no third party; figures are on `/admin/statistik`, on each location's
Statistik tab and in `/konto`. The beacon (`POST /_stats`) appends to `var/stats/spool/<Y-m-d>.ndjson`
and never touches the database. The `stats-ingest` step of `bin/cron-daily` (03:15) runs
`app:stats:ingest`, which loads the files into `stats_events` and rebuilds `stats_daily`. The
"Jetzt aktualisieren" button runs the same job at once. A crashed run leaves a `.claimed` file that
the next run loads; a file is never loaded twice. By hand: `php bin/console app:stats:ingest`.

## Mail

Mail goes through Infomaniak's authenticated SMTP, `mail.infomaniak.com:587` with STARTTLS, from the
mailbox `hallo@deine-eventlocation.ch` (an Infomaniak Mail Service on the domain). Give the mailbox
a password of **letters and digits only**, so the DSN needs no escaping; the `@` of the login stays
`%40`, as in the template. The zone needs Infomaniak's SPF include and DKIM record (the Mail Service
shows both), and a DMARC record on `_dmarc` (`v=DMARC1; p=none; rua=mailto:hallo@deine-eventlocation.ch`).
The VPS itself sends nothing over port 25. Send the
mailbox one mail from outside to prove it receives, then test sending with the sender named
(`mailer:test` otherwise sends from `from@example.org`):

```sh
php bin/console mailer:test --from hallo@deine-eventlocation.ch <address outside deine-eventlocation.ch>
```

The message must arrive with `dkim=pass spf=pass dmarc=pass`.

Every inquiry waits for SMTP (delivery is synchronous). `default_socket_timeout=10` in
`public/.user.ini` bounds each blocking read. If `venue_mail_sent_at - created_at` stays above
about 2 s, that is a follow-up (an async transport); the VPS has no local mail queue to fall back
on.

## Backups and restore

| What | Where | Reach |
|---|---|---|
| Database, first line | Neon's point-in-time restore (PITR) | 6 hours on Free, up to 7 days on Launch, to the second |
| Database, outside Neon | `backup.yaml`: `pg_dump`, then a test restore | Plain artifacts in GitHub, 7 days |
| Media originals | `bin/cron-media-backup` every night at 04:30: restic, encrypted, to Infomaniak Object Storage in Switzerland | A snapshot a day for 14 days, a week for 2 months, a month for a year |
| Media (originals and variants), second copy | `bin/pull-media-backup`, by hand from the Mac, from `shared/` | As far back as the backup disk keeps it; nothing is deleted |
| Secrets | `shared/.env.local` | A copy in the owner's password manager |
| Code | Every release is a commit on `main`; the VPS keeps the last three | Rebuild with a deploy |

The releases hold no state, so a lost VPS is: setup steps 1 to 8, one deploy, the media restore
below. Never run `pg_dump` on the server: Debian's client is not Neon's major. The dump runs in
Actions with a Postgres 18 image.

### When to enable the nightly backup

`backup.yaml` is `workflow_dispatch` only until the owner adds a `schedule:`. A scheduled run fails
every night until `NEON_BACKUP_URL` exists and production has venues (the `allow_empty` input covers
a manual run only). So: import the first content, add the **repository secrets** `NEON_BACKUP_URL`
(the database's direct string; pg_dump uses libpq directly, so it may keep `channel_binding=require`)
and optionally `SENTRY_CRON_URL`, run it once by hand and check it is green, then add the schedule.

### Restore a dump locally

Do this once before go-live. Download the artifact of a Backup run and unzip it
(`deine-eventlocation-<time>.dump`). The dump holds every inquiry in clear text, so delete it
afterwards. In a local checkout this replaces the dev database:

```sh
docker compose exec -T database psql -U app -d postgres -c 'DROP DATABASE IF EXISTS app WITH (FORCE)'
make db-create
docker compose exec -T database pg_restore --exit-on-error --no-owner --no-acl --no-publications --no-subscriptions -U app -d app < deine-eventlocation-<time>.dump
docker compose restart php
make sf c="cache:pool:clear cache.content_blocks"
```

Photos need the media backup on top. To go back to demo data: `make db-fresh && make seed`.

### Restore production

There is no scripted restore. Inside Neon's window use its point-in-time restore in the console
(restore the branch to a time, or make a branch at that time and point `DATABASE_URL` at it). For anything
older, `pg_restore` the dump into a new database with a Postgres 18 image and the flags above, then
point `DATABASE_URL` at it (not rehearsed). Either way finish in `<root>/current`, because the
content-block snapshot and the HTTP cache hold the old data, then run the `purge` action:

```sh
php bin/console cache:pool:clear cache.content_blocks
```

### Media backup

Venue photos and PDFs are the one thing we cannot recreate. `bin/cron-media-backup` copies the
originals in `<root>/shared/var/media` every night at 04:30 to Infomaniak Object Storage (S3-compatible,
data in Switzerland) with [restic](https://restic.net):

- restic encrypts on the server with `MEDIA_BACKUP_PASSWORD`; the bucket holds only encrypted chunks.
- It uploads only chunks the repository does not hold yet and skips the snapshot when nothing changed,
  so an unchanged night copies nothing.
- Retention, counted back from the newest snapshot: one a day for 14 days, one a week for 2 months,
  one a month for a year. Older data is pruned.
- The variants and published PDFs in `shared/public/media` are not copied: `app:media:regenerate`
  rebuilds both from the originals.
- After a good backup the script writes the time into `<root>/shared/var/log/media-backup.ok`.
  `sh bin/cron-media-backup status` exits 1 unless that is less than 26 hours old (the F16 launch
  check); it needs no network.
- The snapshots carry the host name `deine-eventlocation.ch`, not the machine's, so a rebuilt server
  continues the same history.

`make deploy-test` runs the script with restic 0.18.0 against a local S3 server: refusals, `init`, a
first run, a second run that copies nothing, retention, `status` and the restore drill below.

#### Setup

Once, after the first deploy (the script runs from `current/`). TODO(owner): the bucket and keys.

1. In the Infomaniak Manager, open (or create) a Public Cloud project, create a private bucket
   `deine-eventlocation-media` in Object Storage and S3 credentials for the project (Horizon: API
   Access, or `openstack ec2 credentials create`). Note the S3 endpoint of the project's region
   (`s3.pub1.infomaniak.cloud` for dc3-a, `s3.pub2.infomaniak.cloud` for dc4-a; check Infomaniak's
   page). Create the bucket first: on a missing bucket restic retries for minutes instead of failing.
2. Fill the `MEDIA_BACKUP_*` lines in `shared/.env.local`: the repository is
   `s3:https://<endpoint>/<bucket>/deine-eventlocation`, the password `openssl rand -hex 32`. Put the
   password into the password manager now: without it the backup cannot be read.
3. Create the repository and run the first backup by hand:

   ```sh
   cd <root>/current
   sh bin/cron-media-backup init       # "created restic repository ..."
   sh bin/cron-media-backup            # prints nothing on success
   sh bin/cron-media-backup status     # "the last backup finished at ..."
   sh bin/cron-media-backup restic snapshots
   ```

4. The crontab line is in `deploy/crontab.txt`. Add the Sentry cron monitor (see
   [Monitoring](#monitoring)), then do the restore drill.

`sh bin/cron-media-backup restic <args>` runs any restic command with the settings of `.env.local`
(`snapshots`, `ls latest`, `restore`, `check`, `unlock`, `stats`).

#### Restore drill

Before go-live, then every six months. It restores into a scratch directory and changes nothing:

```sh
cd <root>/current
media=/var/www/deine-eventlocation.ch/shared/var/media
sh bin/cron-media-backup restic snapshots
sh bin/cron-media-backup restic restore "latest:$media" --target /var/tmp/media-drill
diff -r /var/tmp/media-drill "$media" && echo identical
rm -rf /var/tmp/media-drill
sh bin/cron-media-backup restic check --read-data-subset=10%
```

`latest:<path>` restores the media folder itself, not the whole path from `/`: as `deploy`, restic may
not set the mode of `/var` and `/var/www` and would end with errors. `/var/tmp` is on disk (Debian 13
keeps `/tmp` in RAM). `diff` lists only uploads and deletions since the last backup; `check` reads a
tenth of the stored data back and verifies it.

#### Restore after losing the server

Setup steps 1 to 8 with `.env.local` from the password manager (its `MEDIA_BACKUP_*` lines included),
then the first deploy, which creates an empty `shared/var/media`. Then, as `deploy`:

```sh
cd <root>/current
media=/var/www/deine-eventlocation.ch/shared/var/media
sh bin/cron-media-backup restic restore "latest:$media" --target "$media"
php bin/console app:media:regenerate
```

`app:media:regenerate` renders the variants and publishes the PDFs again and exits 1 if an original is
missing; then run the `purge` action. To get back one file or an older state, find it with
`restic snapshots` and `restic ls <snapshot>`, restore it with
`restic restore <snapshot>:$media --target /var/tmp/one`, copy the file into place and delete `/var/tmp/one`. A file brings
back no database row: the photo shows only where the database still (or again) points at it.

#### Second copy on the Mac

`bin/pull-media-backup` pulls originals and variants to an external disk, additively. Set `PROD_SSH`
and `BACKUP_DIR`, then:

```sh
BACKUP_DIR=/Volumes/Backup/deine-eventlocation sh bin/pull-media-backup
BACKUP_DIR=/Volumes/Backup/deine-eventlocation sh bin/pull-media-backup --verify
```

`--verify` compares checksums without copying and exits 1 if a server file is missing or differs. To
restore from it, rsync back (nothing is deleted on the server):

```sh
rsync -rltz "$BACKUP_DIR/var-media/" "$PROD_SSH:/var/www/deine-eventlocation.ch/shared/var/media/"
rsync -rltz "$BACKUP_DIR/public-media/" "$PROD_SSH:/var/www/deine-eventlocation.ch/shared/public/media/"
```

## Monitoring

**Sentry** (an organisation in the EU region, `de.sentry.io`) reports production errors. Put the DSN into `SENTRY_DSN`, then run
`php bin/console sentry:test` and open the event. A console event has no request part; check on the
first web error that it shows no cookies, no request body and no IP address. Each event carries the
deployed sha as its release. Sentry runs in production only.

**Uptime** is a Sentry uptime monitor on `https://deine-eventlocation.ch/robots.txt`, every 5
minutes. Use `/robots.txt`, not `/health`: it needs no database, so the check never wakes Neon.

**The backup** reports to a Sentry cron monitor through `SENTRY_CRON_URL`; the monitor also tells you
when the job stopped running at all. **Cron:** cron's mail to `MAILTO` is the signal (see
[Cron](#cron)). Add a Sentry cron monitor `cron-daily` (daily, 03:15 Europe/Zurich, 30 minutes
grace) and have the crontab line ping it after a good run, so a failing or missing night is noticed
even when no mail arrives:
`15 3 * * * /bin/sh /var/www/deine-eventlocation.ch/current/bin/cron-daily && curl -fsS -o /dev/null '<monitor url>?status=ok'`.
That line is the owner's edit on the server, not in `deploy/crontab.txt`, because the URL is a secret.
The same for the media backup: a monitor `media-backup` (daily, 04:30 Europe/Zurich, 60 minutes grace),
and on the server
`30 4 * * * /bin/sh /var/www/deine-eventlocation.ch/current/bin/cron-media-backup && curl -fsS -o /dev/null '<monitor url>?status=ok'`.

**The server:** `unattended-upgrades` installs Debian's security updates; check `apt list --upgradable`
and the free disk (`check` prints it) once a month. A PHP minor upgrade from sury is
`apt upgrade`, then `systemctl reload php8.5-fpm`.

## Neon plan

Launch is the recommended plan, autoscaling between 0.25 and 0.5 CU with scale-to-zero after 5
minutes (about USD 19 a month at 0.25 CU always on). Free is the fallback: 100 CU-hours a month, and
running out suspends the compute until the month ends. With `stale-if-error`, public pages survive
that for a day at most; `/admin`, inquiries and every uncached page fail at once. What wakes Neon:
crawlers on filter URLs, every `/admin` request, every inquiry and phone reveal, the Statistik
button, the 03:15 job and the nightly backup (the statistics beacon does not). Review the projected
cost after the first week and the first month; on Free move to Launch if the month projects above 80
CU-hours.

## Known limits

- **Rate limits** count per server in `shared/var/share/prod/pools`, across releases because
  `framework.cache.prefix_seed` is fixed (`cache.yaml`); a second server would count
  separately. They take a `flock` lock (`var/lock`). Their key is an HMAC of the IP with `APP_SECRET`.
  A repeat POST costs a token, so a double click uses 2 of the 5 inquiries per hour.
- **PHP settings:** `expose_php=Off` in `.user.ini` has no effect (PHP reads it from `php.ini` only);
  nginx removes `X-Powered-By` (`fastcgi_hide_header`) instead. `opcache.preload` stays off: it would
  need a php-fpm restart on every deploy.
- **`--prune`:** `app:venues:import --prune` has no guard against an empty `venues/` folder.
  `bin/prod-import` refuses it; never run the command directly against production.
- **`deploy-gate`, the nginx file and the pool file** are copied by hand. A deploy does not update
  them: after a change repeat the `cp`/`scp` of [step 4](#4-php-fpm-nginx-and-the-certificate) or
  [step 7](#7-deploy-key-and-gate) and reload the service.
- **The web process owns the code.** php-fpm runs as `deploy`, the user that owns the releases. Simple
  (one user, ≤2 staff), at the cost that a PHP-level compromise can change code; a separate web user is
  a follow-up if that ever matters.
- **Not yet proven against the VPS and Neon:** whether a Neon wake from suspend fits inside
  the 10 s connect timeout (libpq applies it per IP address); whether `PGCHANNELBINDING=require`
  connects through the app's path (`deploy/probe.sh` tests only the DSN path); the host's libpq
  version (below 13 ignores channel binding silently, below 14 has no SNI and needs the `endpoint=`
  form); a worker's persistent connection opened before the setting keeps its old state, and
  `bin/prod-import` runs with `sslmode=require` only.
