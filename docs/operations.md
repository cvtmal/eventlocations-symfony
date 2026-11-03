# Production operations

This is the runbook for the live site: Hostpoint runs PHP, Neon runs PostgreSQL, GitHub Actions
builds and ships every release, and the owner imports content from the Mac. The site has one
operator, so each section says what to run, what a good result looks like and what to do when
it goes wrong.

The account user is `djweddin`. `PROD_SSH` below is the owner's own unrestricted login,
`djweddin@<host from the panel>`, set in the shell and never in a file.

PHP on the server is `/usr/local/php85/bin/php`. The server's Composer runs on an older PHP and is
never needed, because CI ships `vendor/`. Console commands below run in the app directory.

## Layout

The app lives in **one directory**, `/home/djweddin/www/hochzeitslocation.ch`, and a deploy updates
it in place. There is no release history on the server and no rollback command.

| Path | What it is | After a deploy |
|---|---|---|
| `~/www/hochzeitslocation.ch` | The app directory | Replaced, except for the four paths below. A file that is not in the tarball is deleted. |
| `.env.local` | Secrets, chmod 600, written by hand | Kept |
| `var/` | Sessions, cache pools, the HTTP cache store, media originals, import staging, locks | Kept |
| `public/media/` | Generated image variants and PDFs | Kept |
| `public/.well-known/` | Whatever Let's Encrypt left there | Kept |
| `~/deploy/hochzeitslocation/deploy-gate` | The forced command of the deploy key, chmod 700 | Not touched: it is outside the app directory and copied by hand |
| `~/deploy/hochzeitslocation/stage` | The unpacked tarball during a deploy | Deleted when the deploy ends |

Panel docroot: `~/www/hochzeitslocation.ch/public` (**unconfirmed**: the owner has not yet seen the panel accept it; if the panel demands an existing folder, create it empty first and the first deploy fills it).

The docroot must never be the bare app directory, because `.env.local`, `var/` and `vendor/` would
then be served to the public.

Two caches live under `var/share/prod/`, not under `var/cache/prod/`, because `.env` sets
`APP_SHARE_DIR=var/share`:

- `var/share/prod/pools/app` is `cache.app`: the rate-limiter counters, the footer's canton links
  and the content-block snapshot.
- `var/share/prod/http_cache` is the HTTP cache store (#19).

`cache:clear` empties neither. Both survive a deploy because `var/` is protected. A deploy empties
the HTTP cache store with `app:http-cache:clear`. If `var/share/prod` stops being writable, every
URL answers 500, `/health` included. A 500 on `/assets/` too points at `public/.htaccess` instead,
see [When a deploy fails](#when-a-deploy-fails).

`.user.ini` and `.htaccess` in `public/` are versioned. Never use the panel's "PHP settings": it
writes the same `.user.ini`, and the next deploy replaces it. A change to `.user.ini` takes effect
after `user_ini.cache_ttl`, which is 300 s by default.

## First-time setup

Do these in order. Steps 1 to 6 happen once. `deploy/` is not in the tarball, so everything named
`deploy/…` is copied from a checkout on the Mac.

### Before you start

These are the accounts and choices only the owner can make. Nothing below works without them, and
steps 1 and 2 already use the first two.

1. **Neon project.** Create it in the region **`aws-eu-central-1` (Frankfurt)** on **Postgres 18**.
   Both are set when the project is created, and changing either later means moving the data. The
   Datenschutz page states Frankfurt as a fact. Pick the plan from [Neon plan](#neon-plan), and keep
   the direct connection string in the password manager.
2. **SSH.** Enable SSH for `djweddin` in the panel, add your own key without restrictions, and note
   the host. This login is `PROD_SSH`.
3. **Mailbox.** Create `hallo@hochzeitslocation.ch` in the panel; if it wants the domain first, add
   the domain, then the mailbox. Give it a password of letters and digits only (see [Mail](#mail)):
   step 5 needs it.
4. **Sentry.** Create the organisation with the data storage location **EU**, which cannot be changed
   later, then one PHP project, one cron monitor and one uptime monitor (see
   [Monitoring](#monitoring)). Without Sentry, `SENTRY_DSN` stays empty and
   `legal.privacy.hosting.errors` and its template line are deleted.
5. **Backup workflow off.** As soon as `backup.yaml` is on `main`, disable it: **Actions → Backup →
   ⋯ → Disable workflow**. It stays off until
   [When to enable the nightly backup](#when-to-enable-the-nightly-backup).

### 1. Website, DNS and directories

In the Hostpoint panel, create the website `hochzeitslocation.ch` with the alias `www` and PHP 8.5,
and set the docroot from [Layout](#layout). Keep the panel's nginx cache off, because nothing on
the server can purge it. Move the nameservers to Hostpoint (on 2026-10-31 they were still at Wix),
then point A and AAAA for the apex and `www` at the server. Turn on Let's Encrypt for both names
only after the apex resolves to Hostpoint.

Create the directories. No deploy creates `var/media`, because `var/` is outside the sync:

```sh
ssh "$PROD_SSH" 'mkdir -p deploy/hochzeitslocation www/hochzeitslocation.ch/public www/hochzeitslocation.ch/var/media'
```

### 2. Probe the account

`deploy/probe.sh` (over SSH) and `deploy/probe.php` (on the web, in the docroot from step 1) answer
what the rest of this page assumes: PHP extensions, libpq version, the server's time zone, whether a
proxy sits in front of PHP, and the upload and timeout limits. The headers of both files say how to
run them. Delete the probe files and `~/probe.env` in the same session.

Read five answers before the first deploy:

- `flock` must say `works` in both probes, or every limited route fails.
- `opcache.validate_timestamps` must be on with a short `opcache.revalidate_freq`, or the web keeps
  old bytecode after a deploy.
- A `Use php-fpm` line in the document root's `.htaccess` must go into `public/.htaccess`, because a
  deploy replaces that file.
- `zend.exception_ignore_args` in `probe.sh`'s check 12 should be `'1'`. `public/.user.ini` never
  reaches the console, so the host's `php.ini` decides, and at `'0'` the stack frames of console
  commands keep their arguments in Sentry. To change that, pass `-d zend.exception_ignore_args=1` in
  the cron scripts.
- `pm.max_requests` prints `not visible`, because PHP cannot read it: **ask Hostpoint**. #25 needs
  the answer, together with `disable_functions` and the worker count, which the web probe prints.

### 3. Deploy key and gate

On the Mac, in a checkout (`var/` is git-ignored):

```sh
ssh-keygen -t ed25519 -N '' -C github-deploy -f var/deploy_key
scp bin/deploy-gate "$PROD_SSH":deploy/hochzeitslocation/deploy-gate
ssh "$PROD_SSH" 'chmod 700 deploy/hochzeitslocation/deploy-gate'
```

Add `var/deploy_key.pub` to `~/.ssh/authorized_keys` on the server, with the prefix shown in
`deploy/authorized_keys.example`. The path there is absolute because `authorized_keys` does not
expand `$HOME`. Check that the panel did not rewrite the line.

Test the key. The first command must fail with an error about the missing `bin/deploy`, because
nothing is deployed yet. The second must be refused with exit 2 and give no shell:

```sh
ssh -i var/deploy_key -o IdentitiesOnly=yes djweddin@<host> check
ssh -i var/deploy_key -o IdentitiesOnly=yes djweddin@<host> id
```

### 4. GitHub secrets

In the GitHub environment `production`, set the four secrets `deploy.yaml` reads:

| Secret | Value |
|---|---|
| `HOSTPOINT_SSH_KEY` | The content of `var/deploy_key`. Delete `var/deploy_key*` afterwards. |
| `HOSTPOINT_SSH_HOST` | The host from the panel |
| `HOSTPOINT_SSH_USER` | `djweddin` |
| `HOSTPOINT_KNOWN_HOSTS` | The output of `ssh-keyscan <host>`, checked against the fingerprint in the panel |

The backup's secrets are different: see
[When to enable the nightly backup](#when-to-enable-the-nightly-backup).

### 5. `.env.local`

Copy the template, then fill it in on the server, not on the Mac:

```sh
scp deploy/env.local.example "$PROD_SSH":www/hochzeitslocation.ch/.env.local
ssh "$PROD_SSH" 'chmod 600 www/hochzeitslocation.ch/.env.local'
```

Replace every `__PLACEHOLDER__`, generate `APP_SECRET` and `ADMIN_TOTP_KEY` with the one-liners in
the file's comments, and copy every value into the password manager. `__MAILER_PASSWORD__` is the
mailbox's password from [Before you start](#before-you-start). See [`.env.local`](#envlocal) for the
rules, especially for Neon's connection string.

### 6. First deploy, crontab, content

1. Run the Deploy workflow (see [Deploy and purge](#deploy-and-purge)).
2. Enter the crontab (see [Cron](#cron)), then run the nightly job once by hand. It must print
   nothing and exit 0:
   ```sh
   ssh "$PROD_SSH" 'cd www/hochzeitslocation.ch && sh bin/cron-daily'; echo "exit $?"
   ```
3. Import the content (see [Content import](#content-import)) **before any staff edit**.
4. Create the first admin. The command asks for the password twice:
   ```sh
   ssh -t "$PROD_SSH" 'cd www/hochzeitslocation.ch && /usr/local/php85/bin/php bin/console app:admin:create-user <e-mail> --role=admin'
   ```
   Then sign in at `/admin`; the first login enrols the authenticator app.
5. Set up mail, monitoring and backups: see [Mail](#mail), [Monitoring](#monitoring) and
   [Backups and restore](#backups-and-restore).

## Deploy and purge

Both run from **Actions → Deploy → Run workflow**, on `main`. The input `action` chooses.

**`deploy`** refuses unless CI passed on that commit. It builds the tarball in CI, because the
server has no Node and no Sass, and pipes it through the gate. On the server:

1. The gate takes a lock and unpacks the tarball into `~/deploy/hochzeitslocation/stage`. It then
   runs the stage's own `bin/deploy install <sha>`, so a release is installed by its own newest
   logic.
2. `bin/deploy` checks that the tarball is complete and that `.env.local` exists, then runs
   `app:prod:check-env` from the stage, against the real `.env.local`.
3. Migrations run from the stage, while the old code is still live. That is safe because
   migrations are additive.
4. `rsync --delete` copies the stage over the app, keeping the four protected paths.
5. In the app: the shell empties `var/cache/prod`, then `cache:warmup`, `app:geo:import-towns` and
   `app:http-cache:clear`.

The sync takes seconds. During those seconds the live tree is a mix of old and new files, and a
request can fail. This is accepted. The compiled cache goes right after the sync, so from then on
the old container no longer runs the new code. Until `cache:warmup` takes its lock, a request can
compile the container itself, which is slow. If that request still runs OPcache's old copy of a
class whose wiring this release changes, the compiled container is wrong: the deploy stays green,
the smoke tests pass, and one page stays at 500. It is rare, because it also takes a request that
loaded that class shortly before the sync. The remedy is the last row under
[When a deploy fails](#when-a-deploy-fails).

The workflow then smoke-tests `/health`, the security headers and gzip on the listing, the sitemap
and `robots.txt`, the `www` and `http` redirects, the admin login's `no-store`, a media file, and
finally `check` on the server. These tests always use `https://hochzeitslocation.ch`, so they fail
until DNS and the certificate are ready, even when the install was fine. Read which step went red.
Before DNS moves, this checks the server directly; `-k` only skips the certificate check:

```sh
curl -kI --resolve hochzeitslocation.ch:443:<server ip> https://hochzeitslocation.ch/health
```

Hostpoint's preview host cannot stand in for this: production answers 400 to every host other than
`hochzeitslocation.ch` and `www.hochzeitslocation.ch`.

**`purge`** empties the HTTP cache on the server.

### When a deploy fails

| The log says | State of the site | What to do |
|---|---|---|
| `…, nothing was changed` | Old version, untouched | Fix the cause (usually `.env.local`, the guard names the variable), deploy again |
| `the console in … does not boot`, then `the env guard could not run, nothing was changed` | Old version, untouched | The log withholds the console's output, because it can quote a secret. Over SSH, in the app directory: `/usr/local/php85/bin/php bin/console about`. It usually names a line of `.env.local`. If it passes there, the release itself does not boot: fix it on `main` and deploy again. On the first deploy the app directory has no `bin/console` yet and the stage is already gone, so that command cannot run: compare `.env.local` with `deploy/env.local.example` from a checkout, fix the file, deploy again |
| `the migration failed, the app is unchanged` | Old code. Migrations that finished before the failing one stay applied. | Read the error, fix on `main`, deploy again |
| `the sync failed, the live tree may be a mix of old and new files` | Mixed old and new files, and a request can fail. The migrations have run. | Fix what rsync's line above names, usually the disk quota (`sh bin/deploy check` shows the free disk), then deploy again. The deploy repeats the sync, and the migrations have nothing left to do |
| `files are live but cache:warmup failed`, or `… var/cache/prod could not be emptied` | New files, no compiled cache. The site may answer 500. | Run the command the log prints, in full: `cd ~/www/hochzeitslocation.ch && rm -rf var/cache/prod var/cache/prod.old && /usr/local/php85/bin/php bin/console cache:warmup`. Never `cache:clear`: it boots on the old container and dies the same way. Then do the next two rows |
| `files are live but the towns import failed` | New version live, old pages cached: the deploy stopped before the HTTP cache clear | Run `/usr/local/php85/bin/php bin/console app:geo:import-towns` in the app directory, then do the next row |
| `files are live but the HTTP cache could not be cleared` | New version live, old pages cached | Run the `purge` action |
| `purge` ends with `the console in … does not boot` | Nothing was cleared, old pages stay cached | Same check over SSH as in the row on the env guard, then run the `purge` action again |
| `another deploy is running` | A deploy is running, or one died | If none is running, `rm -r ~/deploy/hochzeitslocation/.lock` |
| The `/health` smoke check is red and every URL answers 500, assets included | New files live, nothing serves: Apache refuses `public/.htaccess` | Check the `Use php-fpm php85` line first: Hostpoint may not take it. Revert the `php-pin` commit on `main`, not only the line, because a test pins it and Deploy needs green CI. Deploy again: it runs over SSH, so it needs no working site |
| Nothing: the deploy was green, but a page answers 500 (a 500 reaches Sentry) | That page is broken, the rest serves | Run the warmup row's command, in full: `cd ~/www/hochzeitslocation.ch && rm -rf var/cache/prod var/cache/prod.old && /usr/local/php85/bin/php bin/console cache:warmup`. Or deploy again |

To see what is deployed, run this in the app directory, which prints `OK`, the sha, the PHP version
and the free disk:

```sh
sh bin/deploy check
```

### Going back

There is no rollback. To go back, **revert the commit on `main`, let CI pass, and run a deploy**. A
run always deploys the head of `main`, and the workflow has no input for an older commit.

Migrations are never reverted, so the reverted code runs on the newer schema. That works because
migrations are additive. If data itself is wrong, see [Backups and restore](#backups-and-restore).

## `.env.local`

`deploy/env.local.example` is the template. A deploy leaves the file alone, and `bin/deploy` changes
nothing while the file fails `app:prod:check-env`. The command prints variable names, never values.

| Variable | Rule |
|---|---|
| `APP_ENV`, `APP_DEBUG` | `prod` and `0` |
| `APP_SECRET` | At least 32 characters |
| `DEFAULT_URI` | Exactly `https://hochzeitslocation.ch` |
| `DATABASE_URL` | Neon's direct string, see below |
| `PGCHANNELBINDING` | `require`, unless the probe shows it cannot connect |
| `MAILER_DSN` | A real transport, never `null://` or Mailpit |
| `MAILER_FROM` | `Hochzeitslocation <hallo@hochzeitslocation.ch>` |
| `INQUIRY_RECIPIENT_OVERRIDE` | **Must stay empty.** Any value sends every venue's inquiries and every CRM mail to that one address. |
| `ADMIN_TOTP_KEY` | Base64 of 32 bytes, different from the dev key in `.env` |
| `ADMIN_DEV_AUTOLOGIN` | Must stay empty |
| `MEDIA_ROOT` | `/home/djweddin/www/hochzeitslocation.ch/var/media`, absolute, inside `var/` so it survives a deploy |
| `MEDIA_MAX_PIXELS`, `MEDIA_MAX_PORTRAIT_RATIO` | `20000000` and `2` for a CMS photo. Raise the pixels only together with `memory_limit` in `public/.user.ini`. |
| `GOOGLE_MAPS_EMBED_KEY` | Optional. Restrict the key to the Maps Embed API and the site's referrer. Empty: only the link to Google Maps. |
| `SENTRY_DSN` | Optional. Empty: no error reports. |
| `SYMFONY_TRUSTED_PROXIES` | Only if the probe shows a proxy in front of PHP. The exact address, never `REMOTE_ADDR`. |

`MEDIA_PUBLIC_DIR` is not set in production; it stays on the default `public/media`.
`SENTRY_RELEASE` is not set here either: each deploy writes it into `.env.prod.local`.

**`DATABASE_URL`.** Neon's console hands out a string ending in
`sslmode=require&channel_binding=require`. **Delete `channel_binding=require`.** DBAL drops it and
`connect_timeout` without a word, so they would look like protection and give none, and
`app:prod:check-env` refuses a URL that names either of them. Both exist elsewhere:

- The connect timeout is `PDO::ATTR_TIMEOUT: 10` in `config/packages/doctrine.yaml`, under
  `when@prod`.
- Channel binding is the line `PGCHANNELBINDING=require` in `.env.local`.
  `ChannelBindingMiddleware` hands that one variable to libpq just before each connection.

`DATABASE_URL` must contain no `%`, because Symfony resolves the value, so a password that needs
percent-encoding gets replaced rather than encoded. If the probe shows libpq below 14, use the
commented `endpoint=` form in the template and drop `PGCHANNELBINDING`.

**Applying a change.** There is no compiled env file, so an edit applies on the next request and
needs no deploy. Run `sh bin/deploy check` in the app directory afterwards.

**Rotating `APP_SECRET`.** Generate a new value with the template's one-liner, replace it in the
file and in the password manager, and run `check`. The cost is small and passes within minutes: a
guest with the inquiry form open sees the "too fast" error once and sends it again, a confirmation
link already open stops showing the confirmation, a double submit across the change is not
recognised as one, and the counters of the inquiry and phone limiters start again.

**Losing `ADMIN_TOTP_KEY`.** It encrypts the staff's authenticator secrets. Without it, nobody can
pass the second login step until an admin has been reset. Set a new key, run this for every staff
member, and each enrols again at the next login:

```sh
/usr/local/php85/bin/php bin/console app:admin:reset-2fa <e-mail>
```

A forgotten password is `app:admin:reset-password <e-mail>`.

## Content import

The server has too little memory and a slow AVIF encoder, so it never imports. `bin/prod-import`
runs in the local php container against Neon and sends the media up with rsync.

Run it in the **main checkout**, which holds `content/owner/`, the photo cache and the fresh CSV
`content/owner/Locations+Startseite.csv`, with `make up` running. Set both variables in the shell.
`read` keeps the connection string out of the shell history:

```sh
read -rs PROD_DATABASE_URL && export PROD_DATABASE_URL
export PROD_SSH=djweddin@<host>
```

Every command except `help` needs both, `export` included.

| Command | What it does |
|---|---|
| `sh bin/prod-import export` | Writes the content folders from the CSV. Touches neither Neon nor the server. |
| `sh bin/prod-import import` | `app:venues:import` against Neon, media into `var/prod-media` |
| `sh bin/prod-import sync` | rsync to the server. Additive: it deletes nothing there. |
| `sh bin/prod-import purge` | Empties the HTTP cache on the server |
| `sh bin/prod-import all` | `import`, then `sync`, then `purge` |
| `sh bin/prod-import regenerate [--venue=N]` | `app:media:regenerate` against Neon, then `sync`, then `purge` |

**Run order:** first deploy (it creates the schema and the towns), then `export`, then `all`, and
only then may staff edit. Check that the summary shows no failures and that a venue page shows its
photos.

Later imports skip every venue staff have edited and report it as `cms_edited`. That is the import
guard doing its job (see `docs/content-format.md`, "After a CMS edit").

**Never:**

- `--prune`. It unpublishes every venue without a folder, except those staff have edited, so an
  empty or unmounted `venues/` folder takes every other venue offline at once. The script refuses
  it.
- `make seed` or any demo data against production.
- A Neon string in `.env.local` on the Mac, or anywhere else in a file.

To warm the cache afterwards, run `sh bin/warm-cache`, which requests every public page once, one
at a time. It runs by hand only, never from cron.

### Customer sheet (#48)

The owner's customer sheet goes into the CRM once. Put it at `content/owner/kunden-master.csv` in
the main checkout. It runs like the content import: same checkout, same two variables.

| Command | What it does |
|---|---|
| `sh bin/prod-import crm-bootstrap` | `app:crm:bootstrap` against Neon: one company per venue that has none |
| `sh bin/prod-import customers-dry` | `app:crm:import-customers --dry-run` against Neon. Writes only the report. |
| `sh bin/prod-import customers` | `app:crm:import-customers` against Neon |

Both customer commands write the report `content/owner/kunden-import-report.md`. It names rows,
company and venue names and classes, never an email or a phone number.

**Run order:**

1. Make a Neon branch first, so a bad run can be undone.
2. `sh bin/prod-import crm-bootstrap`. It is safe to run again: a second run creates nothing.
   Without it, every row that matches a venue is reported as blocked.
3. `sh bin/prod-import customers-dry`, then read the report. If names need fixing, fix them in the
   sheet and run it again, until the report is right.
4. `sh bin/prod-import customers`.
5. `sh bin/prod-import customers-dry` again. It must show 0 new.

The import only creates. It never changes or deletes a company, so staff edits are safe, before and
after the run. A company staff create between the dry run and the real run is matched by name and
skipped. After the run the sheet is retired: staff edit the CRM by hand. Once staff have renamed
imported companies, don't run the import again, because the old names no longer match.

If the CRM has a task "Google Sheet Kunden importieren", close it by hand.

## Cron

Enter the three lines of `deploy/crontab.txt` in the panel's Cronjob Manager. All paths are absolute.

| Line | Script | What it does |
|---|---|---|
| `5 0 * * *` | `bin/cron-midnight` | Empties the HTTP cache after listings expire at midnight |
| `2-59/5 * * * *` | `bin/cron-cache-guard` | Empties it only when it is over its size or file limit |
| `15 3 * * *` | `bin/cron-daily` | Inquiry purge, CRM stage refresh and renewal tasks, couple purge, inquiry backfill, stuck-inquiry report, cache pool prune, staging, session and limiter-lock sweeps |

The two cache jobs share a lock, `var/cache-clear.lock`. The midnight clear waits up to 60 s for it,
and the guard skips a tick when it is held.

**Time zone.** The lines assume the server runs on Zurich time. If the probe shows UTC, use the two
commented lines in `deploy/crontab.txt` in place of the 00:05 and 03:15 lines; the guard line stays.

**What a mail from cron means.** Hostpoint mails a job's output to the account, and a good night
prints nothing, so every mail is worth reading.

| The mail says | Meaning | What to do |
|---|---|---|
| `cron-daily: inquiries-purge failed`, or `pool-prune` | The retention promise in the privacy text is at risk. Often Neon was unreachable. | Run `sh bin/cron-daily` in the app directory. If it fails again, read the error in the mail. |
| `cron-daily: staging-sweep failed`, `sessions-sweep`, or `lock-sweep` | The sweep could not delete. `bin/cron-daily` creates its three directories itself, so a missing directory is not the cause. | Read the step's own output in the mail, fix that, and run `sh bin/cron-daily` in the app directory |
| `cron-daily: crm-purge failed`, or `crm-backfill`, with lines `<id>: <class>` | One couple could not be erased, or one inquiry could not be ingested. The line names the class only. | Nothing at first: the next night retries it. If the same id comes back, read the class and check that row. A `crm-backfill` line with a count only (`1 inquiries ingested, 0 failed.`) and no "failed" line is a repaired inquiry, not an error. |
| `<id> venue <n> since <time> UTC` | An inquiry stayed `pending`: its process died, and the venue may have no mail | See below |
| `cron-midnight: the cache lock stayed busy for 60 s` | A guard run held the lock | If the next night is clean, nothing. A lock older than 10 minutes is taken over by itself. |

**A stuck inquiry.** Open `/admin/crm/anfragen/<id>` and press Erneut senden.

## Mail

Mail goes through Hostpoint's authenticated SMTP, `asmtp.mail.hostpoint.ch`, port 587 with STARTTLS.
The address is `hallo@hochzeitslocation.ch`. Give the mailbox a password of **letters and digits
only**, so the DSN needs no escaping. The `@` of the login stays `%40`, as in the template.

The zone needs DKIM, SPF `v=spf1 redirect=spf.mail.hostpoint.ch` and, on the host `_dmarc`, the
DMARC record `v=DMARC1; p=none; rua=mailto:hallo@hochzeitslocation.ch`. Send the mailbox one mail
from an outside account, to prove it receives. Then test sending, with the sender named, because
`mailer:test` otherwise sends from `from@example.org`:

```sh
/usr/local/php85/bin/php bin/console mailer:test --from hallo@hochzeitslocation.ch <address outside hochzeitslocation.ch>
```

The message must arrive with `dkim=pass spf=pass dmarc=pass`.

Every inquiry waits for SMTP, because delivery is synchronous. `default_socket_timeout=10` in
`public/.user.ini` bounds each blocking read, so a hanging mail server costs a worker about 10 s per
read rather than 60. Read `venue_mail_sent_at - created_at` from Neon after a few real inquiries.

**The sendmail fallback.** If the median stays above about 2 s, switch `MAILER_DSN` to the commented
sendmail line in the template. It is a one-line change and answers faster, but it has a real cost:
a failed delivery is no longer a 503 that the guest sees and can retry. The mail is accepted
locally, and a failure later comes back as a **bounce to `hallo@hochzeitslocation.ch`**, so someone
has to read that mailbox.

## Backups and restore

| What | Where | Reach |
|---|---|---|
| Database, a mistake just now | Neon's restore window | 6 hours on Free, up to 7 days on Launch |
| Database, durable | `backup.yaml`, nightly at 04:17 UTC: `pg_dump`, then a test restore | Plain artifacts in GitHub, 7 days. A mistake noticed later has no backup. |
| Media | `bin/pull-media-backup`, by hand from the Mac | As far back as the backup disk keeps it: nothing is ever deleted |

`pg_dump` on the host is version 16 and cannot dump Neon 18, so **never run `pg_dump` on the server**.
The backup runs in Actions with a Postgres 18 image.

### When to enable the nightly backup

The schedule starts as soon as `backup.yaml` is on `main`, and it **fails every night** until
`NEON_BACKUP_URL` exists and production has venues and photos. The `allow_empty` input covers a manual run only, never the schedule. So:

1. Keep it disabled from [Before you start](#before-you-start) until the first import is done.
2. Run the first import (see [Content import](#content-import)).
3. Add the secrets. Unlike the deploy secrets, these are **repository secrets**, because the backup
   job does not use the `production` environment:
   - `NEON_BACKUP_URL`: Neon's direct string. pg_dump uses libpq directly, so this one may keep
     `channel_binding=require`.
   - `SENTRY_CRON_URL`: optional, the cron monitor's URL.
4. Enable the workflow, run it once by hand, and check that it is green.

To prove the path earlier, enable it, add `NEON_BACKUP_URL`, dispatch it with `allow_empty` ticked,
and disable it again.

### Restore a dump locally

Do this once before go-live, then after any large change. Download the artifact from the Backup
run and unzip it, which gives a `hochzeitslocation-<time>.dump`.

The `.dump` holds every inquiry in clear text, so delete it when the restore is done. In a local
checkout, this replaces the dev database:

```sh
docker compose exec -T database psql -U app -d postgres -c 'DROP DATABASE IF EXISTS app WITH (FORCE)'
make db-create
docker compose exec -T database pg_restore --exit-on-error --no-owner --no-acl --no-publications --no-subscriptions -U app -d app < hochzeitslocation-<time>.dump
docker compose restart php
make sf c="cache:pool:clear cache.content_blocks"
```

Open `/hochzeitslocations`. Photos need the media backup on top. To go back to demo data, run
`make db-fresh && make seed`.

### Restore production

There is no scripted restore into production; it needs a calm head and these steps:

- For a mistake inside the window, use Neon's restore in its console.
- For anything older, download the dump as above, `pg_restore` it into a new Neon database with a
  Postgres 18 image, using the flags above, and then point `DATABASE_URL` at it. This path has not
  been rehearsed.

Either way, finish in the app directory, because the content-block snapshot and the HTTP cache still
hold the old data, and then run the `purge` action:

```sh
/usr/local/php85/bin/php bin/console cache:pool:clear cache.content_blocks
```

### Media backup

Once staff upload photos, the originals exist only on the server. Set `PROD_SSH` and `BACKUP_DIR`
(for example, an external disk), then pull regularly and verify:

```sh
BACKUP_DIR=/Volumes/Backup/hochzeitslocation sh bin/pull-media-backup
BACKUP_DIR=/Volumes/Backup/hochzeitslocation sh bin/pull-media-backup --verify
```

`--verify` compares checksums without copying, and exits 1 if a server file is missing or differs.
The result lands in `var-media/` and `public-media/` below `BACKUP_DIR`. Before go-live, copy one
venue's directory out of the backup and open a photo, to prove the backup is readable.

To restore, rsync the directory back. Nothing is deleted on the server:

```sh
rsync -rltz "$BACKUP_DIR/var-media/" "$PROD_SSH:www/hochzeitslocation.ch/var/media/"
rsync -rltz "$BACKUP_DIR/public-media/" "$PROD_SSH:www/hochzeitslocation.ch/public/media/"
```

## Monitoring

**Sentry** reports production errors, in an EU organisation; that region cannot be changed later.
Put the DSN into `SENTRY_DSN`, then send a test and open the event:

```sh
/usr/local/php85/bin/php bin/console sentry:test
```

This event comes from the console, so it has no request part. Check that on the first error of a web
request: it must show no cookies, no request body and no IP address, in a header or anywhere else.
User-Agent, Referer, the URL and its query string do go out, and so does whatever an exception's
message quotes, a failed query's row included.

Sentry runs in production only. Each event carries the deployed sha as its release.

**Uptime** is a Sentry uptime monitor on `https://hochzeitslocation.ch/robots.txt`, every 5 minutes.
Use `/robots.txt`, not `/health`: it needs no database, so the check never wakes Neon. `/health`
queries the database and would keep the compute awake all month.

**The backup** reports to a Sentry cron monitor through `SENTRY_CRON_URL`. The monitor also tells you
when the job stopped running at all, which a red run cannot do.

## Neon plan

Launch is the recommended plan, set to autoscale between 0.25 and 0.5 CU with scale-to-zero after
5 minutes. Even at 0.25 CU always on, it costs about USD 19 a month. Free is the fallback: 100
CU-hours a month, and running out suspends the compute until the month ends. With #19's
`stale-if-error`, public pages survive that for a day at most; `/admin`, inquiries and every
uncached page fail at once.

What wakes Neon: crawlers on filter-chip URLs, every `/admin` request, every inquiry and phone
reveal, the 03:15 cron job and the nightly backup.

**Review after a month.**

- On Launch, note the projected monthly cost after the first week, and again after the first month.
- On Free, read the CU-hours in Neon's console every week of the first month. If the month projects
  above 80, move to Launch.

## Known limits

**HTTP cache:** `HTTP_CACHE_MAX_BYTES` (5 GiB) and `HTTP_CACHE_MAX_FILES` (1,000,000) stay on `.env`'s
values. The file limit is the real bound, about 6.4 GB on disk at 1M files. Check whether the Hostpoint
quota counts blocks, and leave room for one 5-minute interval of overshoot. If the quota is tighter,
set both in `.env.local`.

**Mail from the cache guard:** `bin/cron-cache-guard` prints nothing within the limits. A mail with
`HTTP cache store: … bytes, … files.` means the store hit a limit and was emptied.

**Backups:** the nightly backup fails loudly until `NEON_BACKUP_URL` and the first import exist, and
`allow_empty` covers a dispatch only. See
[When to enable the nightly backup](#when-to-enable-the-nightly-backup).

**PHP settings:** `expose_php=Off` in `public/.user.ini` has no effect, because PHP reads that
setting from `php.ini` only. `public/.htaccess` removes the `X-Powered-By` header instead.

**Rate limits:**

- The limiters count per server, in `var/share/prod/pools`. A second server would count separately.
- They now take a lock through `symfony/lock` (a `flock` store in `var/lock`), so parallel POSTs no
  longer overrun them.
- The inquiry and phone limiters count per IP, but their key is an HMAC of it with `APP_SECRET`, so
  neither a lock file's name nor a cached counter holds the address. The empty lock files stay until
  `lock-sweep` removes those older than 180 minutes, so up to about 27 hours.
- A repeat POST still costs a token, so a double click uses 2 of the 5 inquiries per hour.

**`--prune`:** `app:venues:import --prune` has no guard against an empty `venues/` folder: it would
unpublish every venue staff have not edited. `bin/prod-import` refuses it, so never run the command
directly against production.

**`deploy-gate`:** it is copied by hand. A deploy does not update it, so after a change to
`bin/deploy-gate`, repeat the `scp` and `chmod` of [step 3](#3-deploy-key-and-gate).

**Parked for the probe (not yet proven against Neon or Hostpoint):**

- The CLI's `zend.exception_ignore_args` and Hostpoint's `pm.max_requests`: see
  [step 2](#2-probe-the-account).
- Whether a Neon wake from suspend fits inside the 10 s connect timeout. libpq applies the timeout
  per IP address, so a host with several addresses can take longer.
- Whether `PGCHANNELBINDING=require` connects through the app's path, as `deploy/probe.sh` tests
  only the DSN path.
- Hostpoint's libpq version. Below 13 it ignores `PGCHANNELBINDING` silently; below 14 it has no
  SNI and needs the `endpoint=` form. That form downgrades the login, so it goes without
  `PGCHANNELBINDING`.

**Channel binding has two gaps:** a worker's persistent connection opened before the setting stays
as it was, and `bin/prod-import` runs with `sslmode=require` only.
