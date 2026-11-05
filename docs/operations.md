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
   migrations are additive. The exceptions are #55 and #56, see §CRM contacts switch (#55) and
   §CRM one Eintrag per Location (#56).
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
migrations are additive, except #55's and #56's (their own undo is in §CRM contacts switch (#55)
and §CRM one Eintrag per Location (#56)). If data itself is wrong, see [Backups and restore](#backups-and-restore).

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
| `GOOGLE_MAPS_EMBED_KEY` | Optional. Restrict the key to the Maps Embed API and the referrer `https://hochzeitslocation.ch/*`. Empty: only the link to Google Maps. After a change, run `sh bin/prod-import purge`, or cached pages keep the old map for up to a day. |
| `GOOGLE_MAPS_JS_KEY` | Optional. The near-me map (#52) and the Google rating on venue pages (#53). A separate key, restricted to **Maps JavaScript API and Places UI Kit** and the referrers `https://hochzeitslocation.ch/*` and `https://www.hochzeitslocation.ch/*`. Empty: no map on the near-me page (the list only), and venue pages show only the link to the venue's reviews on Google Maps; neither makes a request to Google. Map loads are billed after 10,000 a month with no cap (owner, 2026-11-05); the rating's cap is optional, see §Google rating (#53). After a change, run `sh bin/prod-import purge`. |
| `GOOGLE_MAPS_MAP_ID` | Required when `GOOGLE_MAPS_JS_KEY` is set: the raster JavaScript Map ID. `app:prod:check-env` refuses `DEMO_MAP_ID` then. After a change, purge as above. |
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

### Near-me map key (#52)

The page `/hochzeitslocation-in-meiner-naehe` shows its list without a key. The map needs a Maps
JavaScript API key and a Map ID, both the owner's to create:

1. In the Google Cloud console, open the project that holds the Embed key, and check under Billing
   that a billing account is linked.
2. Open the **Maps JavaScript API** product page and click **Enable**.
3. Go to **Google Maps Platform → Keys & Credentials → Create credentials → API key**. Name it
   "hochzeitslocation near-me (prod)".
4. In "Select API restrictions", keep **only Maps JavaScript API**, then click **Create**.
5. Open the key. Under application restrictions, choose **Websites** and add
   `https://hochzeitslocation.ch/*` and `https://www.hochzeitslocation.ch/*`. Save.
6. Optional, for the real-key CSP check: repeat steps 3–5 for a **development key**, name
   "near-me (dev)", Maps JavaScript API only, websites `http://localhost:8080/*` and the lane ports
   in use. Keep localhost off the production key. The dev key goes in a lane's git-ignored
   `.env.local`, never in a committed file.
7. Go to **Google Maps Platform → Map Management → Create map ID**. Name "near-me", map type
   **JavaScript**, **Raster**. Copy the Map ID.
8. Optional: **Billing → Budgets & alerts → Create budget**, scoped to this project, with the
   50/90/100 % alerts to the billing admins. A budget alert only warns; it never stops the map.
9. On the server, set `GOOGLE_MAPS_JS_KEY` and `GOOGLE_MAPS_MAP_ID` in `.env.local`, run
   `sh bin/deploy check`, then `sh bin/prod-import purge` so cached pages pick up the map.

There is no quota cap (owner, 2026-11-05): each view of the page with a key is one map load, free up
to 10,000 a month and billed above that without a limit.

### Google rating (#53)

A venue page shows the venue's Google stars and review count when three things hold: the server has
`GOOGLE_MAPS_JS_KEY`, staff have set the venue's Google place ID (CMS card "Google-Bewertung", or
the one-off lookup below), and the switch "Google-Bewertung ausblenden" is off. Google's own element
(Places UI Kit, compact Place Details) draws name, stars, count and attribution in the visitor's
browser, after the page has loaded and once that part of the page is in view. We store only the
place ID; no rating, count or name from Google is stored or cached. Without the key, when the key
or the quota fails, or without JavaScript, the page shows the link "Bewertungen auf Google Maps
ansehen" instead. Our own ratings and reviews are retired (below).

**1. The owner's Google Cloud steps.**

1. Billing address: answered on 2026-11-05, it is in Switzerland, so nothing to change.
2. In the project of the Embed and near-me keys, open **Places UI Kit**
   (`placewidgets.googleapis.com`) and click **Enable**. Enable the **Maps JavaScript API** as well
   if #52's steps have not.
3. Open the `GOOGLE_MAPS_JS_KEY` key → **API restrictions** → add **Places UI Kit** and keep
   **Maps JavaScript API**. Leave the website restrictions as they are. The dev key of #52's step 6
   gets the same addition.
4. **Quota cap, optional** (as #52's budget alert; the owner accepts paying for loads): **APIs &
   Services → Places UI Kit → Quotas**, set the requests per day to, for example, 1,000. That is at
   most about 30,000 a month: 10,000 are free, then USD 1.00 per 1,000. Once the cap is reached,
   venue pages show the link instead of the stars until the next day. This cap is the rating's own;
   #52's "no cap" for the map stays.
5. If the server already has `GOOGLE_MAPS_JS_KEY` (and #52's `GOOGLE_MAPS_MAP_ID`, which
   `app:prod:check-env` requires with it), nothing on the server changes. Otherwise set both as in
   §Near-me map key (#52), run `sh bin/deploy check`, then `sh bin/prod-import purge`.

Each view of a venue page that has a place ID and scrolls the rating into view is one "Places UI
Kit" request. The CMS and `/konto` previews show a note instead and cost nothing.

**Real-key check (owner follow-up).** #53 was built against Google's documentation with a dummy key;
the element has not yet rendered with a real one. Before or right after the release:

1. Give the dev key (#52's step 6) the API restrictions **Maps JavaScript API** and **Places UI
   Kit** and the website `http://localhost:8136/*` (or the port of the lane you use; keep
   localhost off the production key). Put it into the lane's git-ignored `.env.local` as
   `GOOGLE_MAPS_JS_KEY=…`, never into a committed file.
2. Give one dev venue a real place ID, for example
   `UPDATE venues SET google_place_id = 'ChIJ…' WHERE number = 1;` (a venue you know on Google
   Maps), and open its page at 390 and 1440 px.
3. Check and write down:
   - the element's real height at both widths. The box reserves `min-block-size: 7rem` (112 px) in
     `assets/styles/venues/detail/_google-rating.scss` (`.venue-google-rating--live`), a
     provisional value because Google documents no height. Set it to the measured height, so the
     page does not move when the element appears;
   - the look: name, stars and count on our light ground, and Google's attribution visible;
   - the labels are German (`language=de`);
   - the console shows no CSP violation, and the element fires `gmp-load` (the link to Google Maps
     disappears);
   - `make lighthouse URL=/hochzeitslocation/<slug>` with the box in view: LCP and CLS as without
     the key. A miss caused only by Google's script is accepted (#53's Outcome).
4. Afterwards remove the key from `.env.local` and reset the venue
   (`UPDATE venues SET google_place_id = NULL, google_rating_hidden = FALSE WHERE number = 1;`).

A failure here (a CSP violation, a wrong height that moves the page, English labels) goes back to
the dev team; the page keeps showing the link meanwhile.

**2. Finding the place IDs, once.** In the main checkout, with `make up` running, as for
§Content import. The lookup reads Neon, so the venue numbers are production's.

- `PROD_DATABASE_URL` and `PROD_SSH` in the shell, as in §Content import.
- A **Places API (New)** key of its own, restricted to that API **and to your own IP address**. It
  lives in the shell only, never in `.env*`, the repo or the server's files:
  ```sh
  read -rs GOOGLE_PLACES_API_KEY && export GOOGLE_PLACES_API_KEY
  ```
- Cost: one Text Search Pro call per venue without a place ID; 5,000 a month are free.

1. `sh bin/prod-import places-find`. It asks Google for every venue without a place ID (any status)
   and writes `content/owner/google-places-report.csv`, one row per venue: `number`, `name`,
   `published`, `status` (`found`, `not found`, `failed`), `query`, `place_id`, `matched_name`,
   `name_matches` (`yes` or `NO`), `address`, `distance_km` (to the venue's pin; empty when the
   venue has none or only its town centre), `apply`. It writes nothing to the database, and
   `apply` is always empty. It prints `Looked up: F found, M not found, X failed.` and exits 1 when
   any row failed; a rerun asks only the venues still without an ID.
2. Open the report. For each `found` row decide: write `yes` in `apply` when `name_matches` is
   `yes` and `distance_km` is small (under 1 km), or after checking the venue in Google Maps. Leave
   `NO` rows, far rows and `not found` rows empty, and set those by hand in the CMS card
   "Google-Bewertung" (it has an "Auf Google Maps prüfen" link once an ID is saved).
3. `sh bin/prod-import places-apply-dry` until its summary is right:
   `Dry run: A assigned, K kept (already had a place ID), R refused (not a place ID), U unknown venue.`,
   followed by the numbers of the kept, refused and unknown rows. It writes nothing. It exits 1
   when R or U is above 0.
4. `sh bin/prod-import places-apply`. It sets only the `yes` rows, never overwrites a venue that
   already has an ID (`kept`), writes an audit row per venue (actor `console`), and then empties
   the server's page cache. A place ID does not stamp `cms_edited_at`, so later folder imports
   still reach these venues.
5. `unset GOOGLE_PLACES_API_KEY`, and delete the key in the Cloud console.
6. Delete `content/owner/google-places-report.csv`. It holds Google's names and addresses, which
   the Maps terms (§3.2.3) do not let us keep; only the place IDs in the database stay.

**Undo:** clear the ID in the CMS card. Google recommends refreshing place IDs older than 12 months: clear
the venue's ID and rerun steps 1–4. There is no cron.

**3. Retiring our own ratings: the production check before the drop.** #53 removes every reader
and writer of our own rating, rating highlights and reviews (CMS tab "Bewertungen", rating card,
public sections, listing cards, JSON-LD, the content format), but the columns
`venues.rating_score`, `venues.rating_count` and the tables `venue_rating_highlights` and
`venue_reviews` stay until a second commit drops them. That commit, `google-rating-drop`, waits on
its own branch for this check.

Run it **after** the release with #53 is deployed, because only then can nobody add a rating or a
review. In the main checkout, with `make up` running. The php image has no `psql`, so the
`database` service runs it; the connection string reaches the container by name only:

```sh
read -rs PROD_DATABASE_URL && export PROD_DATABASE_URL
docker compose exec -T -e PROD_DATABASE_URL database sh -c 'psql "$PROD_DATABASE_URL"' <<'SQL'
SELECT
  (SELECT count(*) FROM venues WHERE rating_score IS NOT NULL) AS rated_venues,
  (SELECT count(*) FROM venue_rating_highlights)              AS highlights,
  (SELECT count(*) FROM venue_reviews)                        AS reviews,
  (SELECT count(DISTINCT venue_id) FROM venue_reviews)        AS venues_with_reviews;
SQL
```

- **All four are 0:** nothing is lost. Tell the dev lead; `google-rating-drop` may land and go out
  with the next deploy.
- **Any is not 0:** look at the rows first, with the same command and this SQL:
  ```sql
  SELECT v.number, v.name, v.rating_score, v.rating_count FROM venues v WHERE v.rating_score IS NOT NULL ORDER BY v.number;
  SELECT v.number, r.first_name, r.reviewed_month FROM venue_reviews r JOIN venues v ON v.id = r.venue_id ORDER BY 1, 3;
  ```
  Then decide: (a) drop anyway, optionally after saving the rows to a git-ignored file first
  (`\copy` would write inside the container, so use `COPY … TO STDOUT` and redirect):
  ```sh
  docker compose exec -T -e PROD_DATABASE_URL database sh -c 'psql -q "$PROD_DATABASE_URL"' > content/owner/own-ratings-<date>.csv <<'SQL'
  COPY (SELECT v.number, v.name, v.rating_score, v.rating_count FROM venues v WHERE v.rating_score IS NOT NULL ORDER BY v.number) TO STDOUT WITH (FORMAT csv, HEADER);
  SQL
  ```
  (the same with the `venue_reviews` query for the reviews); or (b) keep the tables, and the drop
  commit does not land.
- **The drop must not be deployed until the owner has seen 0 rows or decided about the rows.**
  Neon's point-in-time restore (§Restore production) is the undo for an unwanted drop.

### CRM contacts switch (#55)

#55 makes the CRM's contacts the contact card of their Location (`crm_companies`), and couples
leave the CRM: an inquiry stays an Anfrage (`inquiries`, `crm_inquiry_links` and the `inquiry`
entries keep every row), but no couple is a contact any more. The contact pages, the contact list,
erase, the JSON export, the nightly couple purge and deal contacts are gone. One migration,
`Version20261105193544`, moves the data:

- couple contact rows are deleted;
- the e-mail is unique per Location (`uk_crm_contacts_company_email`), no longer across the CRM;
- a person is copied once onto a Location they belong on but are missing from (same e-mail, or
  same names when there is none): the person of a deal contact link (E6) onto the deal's
  Location, a person with entries on another Location (C6) onto that Location, and every active
  manager onto their venue's Location with the position "Location-Manager";
- a task on a contact moves to its Location; the tasks on nothing (F5, 18 staff to-dos written on
  the task list) stay exactly as they are;
- `crm_activity_contacts` and `crm_deal_contacts` are dropped, the one deal contact link of
  2026-11-05 included;
- `crm_contacts.kind`, `owner_id`, `crm_tasks.contact_id` and `crm_inquiry_links.contact_id` are
  dropped, and `crm_contacts.company_id` becomes NOT NULL: deleting a Location deletes its
  contacts.

The migration guards itself. It runs in one transaction: a failed precondition or postcondition
rolls the whole migration back, so production keeps the old schema and the old code.

**1. Before the deploy (read-only).** In the main checkout, with `make up` running, as in §Google
rating (#53) step 3. The guard counts are the migration's own queries, word for word, and the
labels are those of its `#55 pre:` lines in the deploy log:

```sh
read -rs PROD_DATABASE_URL && export PROD_DATABASE_URL
docker compose exec -T -e PROD_DATABASE_URL database sh -c 'psql "$PROD_DATABASE_URL"' <<'SQL'
SELECT 'A6 companies with 2+ venues' AS metric, (WITH cv AS (SELECT company_id, count(*) AS venues FROM crm_company_venues GROUP BY company_id) SELECT count(*) FROM cv WHERE venues > 1) AS n
UNION ALL SELECT 'V2 venues on 2+ companies', (SELECT count(*) FROM (SELECT venue_id FROM crm_company_venues GROUP BY venue_id HAVING count(*) > 1) x)
UNION ALL SELECT 'C4 non-couple contacts without a company', (SELECT count(*) FROM crm_contacts WHERE kind <> 'couple' AND company_id IS NULL)
UNION ALL SELECT 'C5 couples with a company', (SELECT count(*) FROM crm_contacts WHERE kind = 'couple' AND company_id IS NOT NULL)
UNION ALL SELECT 'D4 staff entries on a couple', (SELECT count(*) FROM crm_activities a WHERE a.type_code <> 'inquiry' AND EXISTS (SELECT 1 FROM crm_activity_contacts l JOIN crm_contacts p ON p.id = l.contact_id WHERE l.activity_id = a.id AND p.kind = 'couple'))
UNION ALL SELECT 'D6 inquiry entries with no company link', (SELECT count(*) FROM crm_activities a WHERE a.type_code = 'inquiry' AND NOT EXISTS (SELECT 1 FROM crm_activity_companies l WHERE l.activity_id = a.id))
UNION ALL SELECT 'E7 deal links to a couple', (SELECT count(*) FROM crm_deal_contacts dc JOIN crm_contacts p ON p.id = dc.contact_id WHERE p.kind = 'couple')
UNION ALL SELECT 'T-couple tasks on a couple', (SELECT count(*) FROM crm_tasks t JOIN crm_contacts p ON p.id = t.contact_id WHERE p.kind = 'couple')
UNION ALL SELECT 'X0 entries with no link before the switch', (SELECT count(*) FROM crm_activities a WHERE NOT EXISTS (SELECT 1 FROM crm_activity_companies l WHERE l.activity_id = a.id) AND NOT EXISTS (SELECT 1 FROM crm_activity_deals l WHERE l.activity_id = a.id) AND NOT EXISTS (SELECT 1 FROM crm_activity_contacts l WHERE l.activity_id = a.id))
UNION ALL SELECT 'C3 couples', (SELECT count(*) FROM crm_contacts WHERE kind = 'couple')
UNION ALL SELECT 'C6 non-couple contacts with entries on another company', (SELECT count(*) FROM crm_contacts p WHERE p.kind <> 'couple' AND EXISTS (SELECT 1 FROM crm_activity_contacts l JOIN crm_activity_companies lc ON lc.activity_id = l.activity_id WHERE l.contact_id = p.id AND lc.company_id IS DISTINCT FROM p.company_id))
UNION ALL SELECT 'E6 deal contact links', (SELECT count(*) FROM crm_deal_contacts)
UNION ALL SELECT 'F1 tasks on a company', (SELECT count(*) FROM crm_tasks WHERE company_id IS NOT NULL)
UNION ALL SELECT 'F2 tasks on a contact', (SELECT count(*) FROM crm_tasks WHERE contact_id IS NOT NULL)
UNION ALL SELECT 'F4 tasks on a deal', (SELECT count(*) FROM crm_tasks WHERE deal_id IS NOT NULL)
UNION ALL SELECT 'F5 tasks on nothing', (SELECT count(*) FROM crm_tasks WHERE num_nonnulls(company_id, contact_id, deal_id) = 0)
UNION ALL SELECT 'I1 active manager access rows', (SELECT count(*) FROM portal_venue_access WHERE status = 'active')
UNION ALL SELECT 'I2 managers with a CRM contact of the same e-mail', (SELECT count(*) FROM portal_users u WHERE EXISTS (SELECT 1 FROM crm_contacts p WHERE p.email = u.email))
UNION ALL SELECT 'B2 venues without a company', (SELECT count(*) FROM venues v WHERE NOT EXISTS (SELECT 1 FROM crm_company_venues x WHERE x.venue_id = v.id))
UNION ALL SELECT 'inquiries', (SELECT count(*) FROM inquiries)
UNION ALL SELECT 'inquiry links', (SELECT count(*) FROM crm_inquiry_links)
UNION ALL SELECT 'inquiry entries', (SELECT count(*) FROM crm_activities WHERE type_code = 'inquiry')
UNION ALL SELECT 'entries', (SELECT count(*) FROM crm_activities)
UNION ALL SELECT 'tasks', (SELECT count(*) FROM crm_tasks)
UNION ALL SELECT 'non-couple contacts', (SELECT count(*) FROM crm_contacts WHERE kind <> 'couple')
UNION ALL SELECT 'audit rows', (SELECT count(*) FROM admin_audit_log);
SQL
```

Then name the rows the deploy will copy, and check where the tasks on nothing come from, with the
same command and this SQL:

```sql
-- E6: each deal contact link. on_deal_location false: the deploy copies the person onto the deal's Location.
SELECT d.name AS deal, c.name AS deal_location, p.first_name, p.last_name, p.email, p.company_id = d.company_id AS on_deal_location
FROM crm_deal_contacts dc
JOIN crm_deals d ON d.id = dc.deal_id
JOIN crm_companies c ON c.id = d.company_id
JOIN crm_contacts p ON p.id = dc.contact_id;
-- C6: each person with entries on another Location, and that Location. The deploy copies the person there.
SELECT DISTINCT p.first_name, p.last_name, p.email, own.name AS own_location, other.name AS other_location
FROM crm_contacts p
JOIN crm_activity_contacts l ON l.contact_id = p.id
JOIN crm_activity_companies lc ON lc.activity_id = l.activity_id
JOIN crm_companies other ON other.id = lc.company_id
LEFT JOIN crm_companies own ON own.id = p.company_id
WHERE p.kind <> 'couple' AND lc.company_id IS DISTINCT FROM p.company_id;
-- I1: each active manager and their venue's Location. already_there false: the deploy adds them as "Location-Manager".
SELECT u.email, c.name AS location, EXISTS (SELECT 1 FROM crm_contacts p WHERE p.company_id = cv.company_id AND p.email = u.email) AS already_there
FROM portal_venue_access a
JOIN portal_users u ON u.id = a.portal_user_id
JOIN crm_company_venues cv ON cv.venue_id = a.venue_id
JOIN crm_companies c ON c.id = cv.company_id
WHERE a.status = 'active';
-- F5: where the tasks on nothing come from. Expected: one row, on_kind empty, count = F5.
SELECT a.changes->>'on' AS on_kind, count(*)
FROM crm_tasks t
JOIN admin_audit_log a ON a.subject_type = 'task' AND a.subject_id = t.id::text AND a.command = 'App\Crm\Command\CreateTask'
WHERE num_nonnulls(t.company_id, t.contact_id, t.deal_id) = 0
GROUP BY 1;
SELECT count(*) AS f5_written_by_staff FROM crm_tasks WHERE num_nonnulls(company_id, contact_id, deal_id) = 0 AND created_by_id IS NOT NULL;
```

Expected on 2026-11-05 (the owner's counts on Neon). The first eight rows
are the guards: any of them above 0 stops the deploy.

| Row | Expected | Above the expected value |
|---|---|---|
| A6 companies with 2+ venues | 0 | Stops the deploy. Tell the lead: that Location must be split first (#56's business) |
| V2 venues on 2+ companies | 0 (a unique key forbids it) | Stops the deploy. Tell the lead |
| C4 non-couple contacts without a company | 0 | Stops the deploy. Staff attach those people to a Location or delete them in the old contact list, then deploy again |
| C5 couples with a company | 0 | Stops the deploy. Tell the lead: deleting those couples would delete staff work |
| D4 staff entries on a couple | 0 | Same as C5 |
| D6 inquiry entries with no company link | 0 | Stops the deploy. Tell the lead: those Anfrage entries would be left with no link |
| E7 deal links to a couple | 0 | Same as C5 |
| T-couple tasks on a couple | 0 | Same as C5 |
| X0 entries with no link before the switch | 0 (not measured on 2026-11-05) | Stops the deploy: the switch deletes no entry unseen. Stop and tell the owner |
| C3 couples | 0 | Never stops it: the deploy deletes those couples, their Anfragen stay |
| C6 non-couple contacts with entries on another company | 1 | More people are copied. The second query names them |
| E6 deal contact links | 1 | More links are dropped, each person copied onto the deal's Location if missing there. The first query names them |
| F1, F2, F4 tasks on a company, contact, deal | 0, 0, 0 | F2 above 0: those tasks move to the contact's Location |
| F5 tasks on nothing | 18 | Staff wrote more to-dos; they stay untouched |
| I1 active manager access rows | 3 | More managers; each gets a row on their Location unless already there (third query) |
| I2 managers with a CRM contact of the same e-mail | 1 | Informational |
| B2 venues without a company | 0 | A manager of such a venue gets no row; staff link the venue to a Location first |
| inquiries, inquiry links, inquiry entries | not measured | Must stay unchanged by the deploy |
| entries | 1204 | Must stay unchanged by the deploy |
| tasks, non-couple contacts | 18, 1255 | The postconditions start from these |
| audit rows | not measured (1505 on a company, 1 on a contact) | Must stay unchanged by the deploy |

The F5 query should give one row, `on_kind` empty, with the F5 count, and `f5_written_by_staff`
the same count. Another result does not stop the deploy (the migration leaves those tasks alone);
tell the lead.

`SELECT version();` must start with `PostgreSQL 18`: the copies use `uuidv7()`, the first
PostgreSQL 18 function a deployed migration calls. On an older server the migration stops with
`function uuidv7() does not exist` and changes nothing.

**2. What the deploy prints.**

- **Every guard 0:** the deploy goes on. Its log shows the `#55 pre:` lines with the numbers above,
  for example `[notice] #55 pre: C6 non-couple contacts with entries on another company = 1`.
- **A guard above 0:** the migration aborts with one message that names every failing row, for
  example
  `#55 precondition failed: C4 = 2 (non-couple contacts without a company); D4 = 1 (staff entries on a couple). See docs/operations.md "CRM contacts switch (#55)".`,
  then `bin/deploy` says `the migration failed, the app is unchanged`. Nothing of #55 has
  changed: the old code serves on the old schema. Migrations before #55 in the same release stay
  applied, as always. Do what the table says for the named rows, tell the lead, and deploy again.
- **A postcondition fails:** `#55 postcondition failed: <check>: expected <n>, got <m>[; <check>: …]. Nothing was changed. See docs/operations.md "CRM contacts switch (#55)".`
  The migration did not do what it promised on this data. Same state as above; tell the lead, do
  not retry. Concurrent traffic cannot cause it: before its first count the migration locks every
  table it counts against writers (`LOCKED`, SHARE ROW EXCLUSIVE mode), so a guest inquiry or a staff or
  `/konto` action sent meanwhile waits the second the migration takes.
- **`canceling statement due to lock timeout`:** a writer held one of those tables for 15 s, so
  the migration gave up before changing anything. Same state as above; deploy again at a quieter
  moment.

**3. The deploy window.** This migration is the one exception to "migrations are additive"
(§Deploy and purge, step 3): it drops tables and columns the old code still reads. For the seconds
between the migration and the sync, the old code meets the new schema: CRM pages can fail, and an
inquiry sent then is saved but not ingested. Deploy on a quiet evening. A manager confirmed in that
window gets no row on their Location: staff add the row on the Location's contact card, or remove
and add the manager on the panel.

**4. After the deploy.** In the app directory over SSH, ingest whatever the window missed (the
night's `cron-daily` would do it too):

```sh
/usr/local/php85/bin/php bin/console app:crm:backfill-inquiries
```

Then, read-only, from the main checkout as in step 1 (this query reads `crm_company_venues`, so
it only works before #56; after #56, use the checks of §CRM one Eintrag per Location (#56)):

```sql
SELECT 'migration head' AS metric, (SELECT max(version) FROM doctrine_migration_versions) AS n
UNION ALL SELECT 'contacts', (SELECT count(*) FROM crm_contacts)::text
UNION ALL SELECT 'contacts without a Location', (SELECT count(*) FROM crm_contacts WHERE company_id IS NULL)::text
UNION ALL SELECT 'contacts with position Location-Manager', (SELECT count(*) FROM crm_contacts WHERE position = 'Location-Manager')::text
UNION ALL SELECT 'active managers missing on their Location', (SELECT count(*) FROM portal_venue_access a JOIN crm_company_venues cv ON cv.venue_id = a.venue_id JOIN portal_users u ON u.id = a.portal_user_id WHERE a.status = 'active' AND NOT EXISTS (SELECT 1 FROM crm_contacts p WHERE p.company_id = cv.company_id AND p.email = u.email))::text
UNION ALL SELECT 'tasks', (SELECT count(*) FROM crm_tasks)::text
UNION ALL SELECT 'tasks on nothing', (SELECT count(*) FROM crm_tasks WHERE num_nonnulls(company_id, deal_id) = 0)::text
UNION ALL SELECT 'entries', (SELECT count(*) FROM crm_activities)::text
UNION ALL SELECT 'entries with no link', (SELECT count(*) FROM crm_activities a WHERE NOT EXISTS (SELECT 1 FROM crm_activity_companies l WHERE l.activity_id = a.id) AND NOT EXISTS (SELECT 1 FROM crm_activity_deals l WHERE l.activity_id = a.id))::text
UNION ALL SELECT 'inquiries', (SELECT count(*) FROM inquiries)::text
UNION ALL SELECT 'inquiry links', (SELECT count(*) FROM crm_inquiry_links)::text
UNION ALL SELECT 'inquiry entries', (SELECT count(*) FROM crm_activities WHERE type_code = 'inquiry')::text
UNION ALL SELECT 'audit rows', (SELECT count(*) FROM admin_audit_log)::text
UNION ALL SELECT 'H1 audit rows on company', (SELECT count(*) FROM admin_audit_log WHERE subject_type = 'company')::text
UNION ALL SELECT 'H2 audit rows on contact', (SELECT count(*) FROM admin_audit_log WHERE subject_type = 'contact')::text
UNION ALL SELECT 'old tables left', (SELECT num_nonnulls(to_regclass('crm_activity_contacts'), to_regclass('crm_deal_contacts')))::text
UNION ALL SELECT 'old columns left', (SELECT count(*) FROM information_schema.columns WHERE (table_name, column_name) IN (('crm_contacts', 'kind'), ('crm_contacts', 'owner_id'), ('crm_tasks', 'contact_id'), ('crm_inquiry_links', 'contact_id')))::text
UNION ALL SELECT 'K2 Liechtenstein venues on a Location', (SELECT count(*) FROM venues v JOIN crm_company_venues x ON x.venue_id = v.id WHERE v.canton_code = 'li')::text;
```

| Row | Expected |
|---|---|
| migration head | `DoctrineMigrations\Version20261105193544` |
| contacts | `non-couple contacts` of the deploy log plus the copies: 1255 + 1 (C6) + 0–1 (E6) + 0–3 (managers), so 1256–1260 with the 2026-11-05 numbers. The step 1 queries say which copies to expect: E6 rows with `on_deal_location` false, the C6 rows, I1 rows with `already_there` false |
| contacts without a Location, active managers missing on their Location, entries with no link, old tables left, old columns left | 0 |
| contacts with position Location-Manager | at least the managers copied |
| tasks | `tasks` of the deploy log minus T-couple (18) |
| tasks on nothing | F5 of the deploy log (18) |
| entries | `entries` of the deploy log (1204): the switch deletes no entry |
| inquiries, inquiry links, inquiry entries, audit rows | as in the deploy log |
| H1, H2 audit rows on company, on contact | as before the deploy (1505 and 1 on 2026-11-05, plus staff edits since) |
| K2 Liechtenstein venues on a Location | 4 |

Finally, check that the E6 and C6 people from step 1 sit on both Locations now (put in the e-mails
the step 1 queries printed; an e-mail-less person: look them up by name on the Location's card):

```sql
SELECT c.name AS location, p.first_name, p.last_name, p.email, p.position
FROM crm_contacts p JOIN crm_companies c ON c.id = p.company_id
WHERE p.email IN ('<e6 e-mail>', '<c6 e-mail>')
ORDER BY p.email, c.name;
```

**Undo.** Revert the #55 commits on `main` and wait for CI. Then restore Neon to just before the
deploy (§Restore production) and run the deploy at once. The migration's `down()` restores the
schema only: the deleted couples, the entry and deal links, the moved tasks' contact, `kind` and
`owner_id` come back only from the restore. Reverting the code alone does not work: the old code
needs the old schema.

### CRM one Eintrag per Location (#56)

#56 moves the Location's Eintrag onto the Location: one migration, `Version20261105214935`, adds
`crm_companies.venue_id` (unique, `ON DELETE SET NULL`: deleting a venue frees its Location),
copies every row of `crm_company_venues` into it and drops that table. Staff now "Eintrag
zuordnen" on a Location without one and "Lösen" on a Location with one; a Location holds one
Eintrag at most. The Locations list gets the tabs "Alle / Mit Eintrag / Ohne Eintrag", and a new
deal takes its Location's Eintrag.

**Deploy #56 alone**, after #55 is live and step 4 of §CRM contacts switch (#55) passed. A deploy
runs each migration in its own transaction (there is no `--all-or-nothing`), so if #56's guard
stopped a deploy that also carried #55, #55's migration would stay applied under the old code.

The migration guards itself, in one transaction: a failed precondition or postcondition rolls it
back, so production keeps the old schema and the old code.

**1. Before the deploy (read-only).** In the main checkout, with `make up` running, as in §CRM
contacts switch (#55) step 1. The counts are the migration's own queries, word for word, and the
labels are those of its `#56 pre:` lines in the deploy log. #55 already proved the server is
PostgreSQL 18 (`down()` uses `uuidv7()`), so `SELECT version();` is not needed again.

```sh
read -rs PROD_DATABASE_URL && export PROD_DATABASE_URL
docker compose exec -T -e PROD_DATABASE_URL database sh -c 'psql "$PROD_DATABASE_URL"' <<'SQL'
SELECT 'A6 companies with 2+ venues' AS metric, (WITH cv AS (SELECT company_id, count(*) AS venues FROM crm_company_venues GROUP BY company_id) SELECT count(*) FROM cv WHERE venues > 1) AS n
UNION ALL SELECT 'V2 venues on 2+ companies', (SELECT count(*) FROM (SELECT venue_id FROM crm_company_venues GROUP BY venue_id HAVING count(*) > 1) x)
UNION ALL SELECT 'A1 companies', (SELECT count(*) FROM crm_companies)
UNION ALL SELECT 'A5 companies with a venue', (SELECT count(DISTINCT company_id) FROM crm_company_venues)
UNION ALL SELECT 'links', (SELECT count(*) FROM crm_company_venues)
UNION ALL SELECT 'B1 venues', (SELECT count(*) FROM venues)
UNION ALL SELECT 'B2 venues without a company', (SELECT count(*) FROM venues v WHERE NOT EXISTS (SELECT 1 FROM crm_company_venues x WHERE x.venue_id = v.id))
UNION ALL SELECT 'K2 Liechtenstein venues on a company', (SELECT count(*) FROM crm_company_venues x JOIN venues v ON v.id = x.venue_id WHERE v.canton_code = 'li');
SQL
```

Expected on 2026-11-05 (the owner's counts on Neon; #55 neither adds nor removes a Location or a
link). The first two rows are the guards: either above 0 stops the deploy.

| Row | Expected | Above the expected value |
|---|---|---|
| A6 companies with 2+ venues | 0 | Stops the deploy. List them with the query below; staff "lösen" the extra Einträge on the old card and give each its own Location with "Location anlegen" on the venue's CRM panel, then deploy again |
| V2 venues on 2+ companies | 0 (a unique key forbids it) | Stops the deploy. Tell the lead |
| A1 companies | 1499 | Locations staff created since. Informational; the postconditions start from the live value |
| A5 companies with a venue, links | 249, 249 | Equal while A6 is 0. More: staff linked venues since |
| B1 venues | 249 | More venues since; informational |
| B2 venues without a company | 0 | A venue without a Location stays without one |
| K2 Liechtenstein venues on a company | 4 | Must stay the same after the deploy |

```sql
-- A6 above 0: the Locations holding 2 or more Einträge.
SELECT c.id, c.name, count(*) FROM crm_company_venues l JOIN crm_companies c ON c.id = l.company_id GROUP BY c.id, c.name HAVING count(*) > 1;
```

**2. What the deploy prints.**

- **Both guards 0:** the deploy goes on. Its log shows the `#56 pre:` lines with the numbers
  above, for example `[notice] #56 pre: links = 249`.
- **A guard above 0:** the migration aborts with one message that names every failing row, for
  example
  `#56 precondition failed: A6 = 1 (companies with 2+ venues). Nothing was changed. See docs/operations.md "CRM one Eintrag per Location (#56)".`,
  then `bin/deploy` says `the migration failed, the app is unchanged`. The old code serves on the
  old schema. Do what the table says, tell the lead, and deploy again.
- **A postcondition fails:** `#56 postcondition failed: <check>: expected <n>, got <m>[; <check>: …]. Nothing was changed. See docs/operations.md "CRM one Eintrag per Location (#56)".`
  The checks are `P-links` (Locations with an Eintrag = links), `P-copied` (every link copied
  exactly), `P-companies` (A1), `P-venues` (B1), `P-B2` and `P-K2`. Same state as above; tell the
  lead, do not retry. Concurrent traffic cannot cause it: before its first count the migration
  locks `crm_companies`, `crm_company_venues` and `venues` against writers (SHARE ROW EXCLUSIVE
  mode), so a staff action sent meanwhile waits the second the migration takes.
- **`canceling statement due to lock timeout`:** a query held one of those tables for 15 s, so
  the migration gave up before changing anything. The first lock lets readers through, but the
  later `ALTER TABLE` and `DROP TABLE` take ACCESS EXCLUSIVE on `crm_companies` and on the public
  `venues` table (the dropped link table's FKs point at both), so a long reader (a cron, a slow
  query) can cause it too. Public venue pages wait while those statements run, normally well
  under a second, at most 15 s. Same state as above; deploy again at a quieter moment.

**3. The deploy window.** The second exception to "migrations are additive" (§Deploy and purge,
step 3): the migration drops `crm_company_venues`, which the old code still reads. For the seconds
between the migration and the sync:

- CRM pages and the venue editor's CRM panel answer 500;
- an inquiry sent then is saved but not ingested (step 4 ingests it, or the night's `cron-daily`);
- a manager confirmed then gets no row on their Location: staff remove and add the manager on the
  panel;
- a venue first published then gets no "Rechnung stellen" task: `CreateInvoiceTaskListener` logs
  it and never retries, so staff add the task by hand.

Deploy on a quiet evening.

**4. After the deploy.** In the app directory over SSH, ingest whatever the window missed:

```sh
/usr/local/php85/bin/php bin/console app:crm:backfill-inquiries
```

Then, read-only, from the main checkout as in step 1:

```sql
SELECT 'migration head' AS metric, (SELECT max(version) FROM doctrine_migration_versions) AS n
UNION ALL SELECT 'locations', (SELECT count(*) FROM crm_companies)::text
UNION ALL SELECT 'with an Eintrag', (SELECT count(*) FROM crm_companies WHERE venue_id IS NOT NULL)::text
UNION ALL SELECT 'venues without a Location', (SELECT count(*) FROM venues v WHERE NOT EXISTS (SELECT 1 FROM crm_companies c WHERE c.venue_id = v.id))::text
UNION ALL SELECT 'Liechtenstein Einträge', (SELECT count(*) FROM crm_companies c JOIN venues v ON v.id = c.venue_id WHERE v.canton_code = 'li')::text
UNION ALL SELECT 'link table left', (to_regclass('crm_company_venues') IS NOT NULL)::text;
```

A Location with 2 or more Einträge is impossible now (`uk_crm_companies_venue_id` is one venue
per Location row), so it is not queried.

| Row | Expected |
|---|---|
| migration head | `DoctrineMigrations\Version20261105214935` |
| locations | A1 of the deploy log (1499 + Locations created since) |
| with an Eintrag | `links` of the deploy log (249) |
| venues without a Location | B2 of the deploy log (0) |
| Liechtenstein Einträge | K2 of the deploy log (4) |
| link table left | `false` |

**Undo.** Revert the #56 commits on `main` and wait for CI. Then restore Neon to just before the
deploy (§Restore production) and deploy at once. Reverting the code alone fails: the old code needs
`crm_company_venues`. `down()` is complete (every Eintrag comes back as a link row, with new ids and
timestamps), so the lead may instead run, in the app directory before deploying the revert,

```sh
/usr/local/php85/bin/php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261105214935' --down --no-interaction
```

which keeps the writes made since the deploy.

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
| `sh bin/prod-import import-dry` | `app:venues:import --dry-run` against Neon: the summary and the venues it would keep. Writes nothing. |
| `sh bin/prod-import sync` | rsync to the server. Additive: it deletes nothing there. |
| `sh bin/prod-import purge` | Empties the HTTP cache on the server |
| `sh bin/prod-import all` | `import`, then `sync`, then `purge` |
| `sh bin/prod-import regenerate [--venue=N]` | `app:media:regenerate` against Neon, then `sync`, then `purge` |

**Run order:** first deploy (it creates the schema and the towns), then `export`, then
`import-dry` until its summary is right, then `all`. Check that the summary shows no failures and
that a venue page shows its photos.

Every import keeps each venue staff or a manager have edited since its last import, and lists it as
`cms_edited`: nothing in its folder reaches it, its coordinates included. That is the import guard
doing its job (see `docs/content-format.md`, "After a CMS edit"). `import-dry` shows that list
before anything is written.

**Never:**

- `--prune`. It unpublishes every venue without a folder, except those staff have edited, so an
  empty or unmounted `venues/` folder takes every other venue offline at once. The script refuses
  it.
- `make seed` or any demo data against production.
- A Neon string in `.env.local` on the Mac, or anywhere else in a file.

To warm the cache afterwards, run `sh bin/warm-cache`, which requests every public page once, one
at a time. It runs by hand only, never from cron.

### Liechtenstein venues (#46), once

Replaced by the next section, which holds the same steps plus a saved report and an import dry
run. Done on production on 2026-11-04.

### Geocode the venues (Q3) and add Liechtenstein (#46), once

**Done on production on 2026-11-04** (the owner). The figures:
- **Geocode (step 4):** 5 folders asked. 4 found, the 4 Liechtenstein venues, with no `NO`. 1 not
  found, the Swiss venue Google had not found on 2026-11-02 either.
- **Import dry run (step 5):** `247 seen, 4 created, 0 updated, 243 unchanged, 0 failed`, 0 kept,
  photos +33.

Production already had the 242 Swiss pins: the go-live import of 2026-11-02 carried their `geo:`,
and the live pages' JSON-LD holds the folders' coordinates. This runbook had read file times the
other way round and expected about 240 "updated" lines. That was wrong; #46's "the rest
unchanged" was right.

The steps stay here for a rerun, for example after a new export or new venues. This section
replaces the standalone #46 runbook: its steps are all here, so run only this list.

One sequence for two jobs, because both arrive with the same import:

- **#46:** the release makes Liechtenstein region `li`, and the export stops skipping its 4 rows.
- **Q3:** the map pins. A venue shows a map only when it has coordinates and the server has
  `GOOGLE_MAPS_EMBED_KEY`, which the owner set on 2026-11-04. The geocoder writes the coordinates
  into the content folders as `geo:`, and the import carries them to production.

Run the steps in order; each is safe to rerun.

What each step touches:
- Steps 2 to 4 write only under `content/` in the main checkout: copies in `owner/`, a `geo:` in
  each `venues/*/venue.yaml` that has none, and `owner/geocode-report.csv`, which every geocode run
  replaces.
- Step 5 reads Neon and writes nothing.
- Step 6 writes the venues in Neon, adds media on the server and empties the server's page cache.
- Steps 7 and 8 are back-office saves.

Deploy first (step 1). Without the #46 release on the server, the import refuses the 4
Liechtenstein folders on the canton code (`fk_venues_canton_code`, exit 1). The other venues are
unaffected.

**Before you start:**

- **Main checkout:** run `git pull --ff-only`, so it holds the commit production runs, then
  `make vendor` and `make up`. Put `PROD_DATABASE_URL` and `PROD_SSH` in the shell, as above.
- **The Places key:** a key of its own, not the embed key. Restrict it to "Places API (New)", and
  to your own IP address if you like. It lives in the shell only: never in `.env*`, the repo or the
  server's files. Each search is a "Text Search Pro" call: 5,000 a month are free, then USD 32 per
  1,000. A run makes two calls per folder without `geo:`, one in the dry run and one in the real
  run: 10 on 2026-11-04. Put it in the shell with:
  ```sh
  read -rs GOOGLE_PLACES_API_KEY && export GOOGLE_PLACES_API_KEY
  ```
- **The embed key's referrer rule** in the Cloud console must allow `https://hochzeitslocation.ch/*`.
  The site sends Google only its origin (`Referrer-Policy: strict-origin-when-cross-origin`), so a
  rule naming a path never matches. `www` only redirects, so it serves no page and needs no rule.
  After any change of the key in `.env.local`, run `sh bin/prod-import purge`; otherwise cached
  pages keep the old map for up to a day.

1. **Deploy.** `bin/deploy` runs the migration, then `app:geo:import-towns`: expect 13 new towns.
   Check that `/hochzeitslocations?canton=li` answers 200 with 0 results and that the place search
   suggests "Vaduz".
2. **Save what is there.** Every geocode run replaces the report and lists only the folders it
   asked, so a rerun would lose the 242 results of 2026-11-02. The tarball takes the venue files as
   they are now, for the undo below.
   Both commands leave an existing copy alone, so a second run keeps the first one:
   ```sh
   cp -n content/owner/geocode-report.csv content/owner/geocode-report-2026-11-02.csv
   [ -e content/owner/venue-yaml-before-q3.tgz ] || tar -czf content/owner/venue-yaml-before-q3.tgz content/venues/*/venue.yaml
   ```
   The saved report has 243 rows: 242 `set`, 1 `not found`, and 13 rows whose `name_matches` is
   `NO` (the name Google found shares no word with ours). The 13 and the 1 are listed for step 8 in
   `content/owner/q3-pin-check.md`, which git ignores, because real names stay out of the repo.
3. **Export:** `sh bin/prod-import export`. Expect 247 venues and 4 new folders `<slug>-<id8>`. The
   243 existing folders stay byte-identical, their `geo:` included, and no line says "not in
   Switzerland".
4. **Geocode the folders without `geo:`.** On 2026-11-04 these were the 4 Liechtenstein folders
   and the Swiss one Google did not find. Since then, only that Swiss one is left without `geo:`.
   1. Dry run first. It writes only the report:
      ```sh
      docker compose exec -T -e GOOGLE_PLACES_API_KEY php php bin/console app:venues:geocode --dry-run
      ```
      Expect `Dry run: … found, … not found, 0 failed, … already had coordinates.`, with one report
      row per folder asked: on 2026-11-04, 5 rows and 242 already had coordinates. A `would set`
      row shows the name and address Google matched.
   2. Run the same command without `--dry-run`. It asks the same folders again and writes `geo:`
      into the ones it finds.
   3. Read the report again. Add every `NO` row and every `not found` row to
      `q3-pin-check.md` for step 8.
   4. If a row reads `failed` (the network, or Google's quota), the command exits 1. Run it again:
      it asks only what is still missing.
   5. When done, run `unset GOOGLE_PLACES_API_KEY`. If you won't need the key again, delete it in
      the Cloud console.
5. **Import dry run:** `sh bin/prod-import import-dry`. It reads Neon and writes nothing. First
   comes one line per venue that would change, such as `created    <folder>`,
   `updated    <folder>` or `cms_edited  <folder>`. On 2026-11-04 those were the 4 `created`
   lines. Then the summary:
   ```text
   Dry run: Venues: 247 seen, 4 created, U updated, N unchanged, 0 failed, 0 unpublished. …
   K venues kept because the CMS edited them; --overwrite-cms-edits replaces them:
   ```
   followed by the list of those folders. The "kept" line and its list appear only when K is more
   than 0, and read "1 venue kept" for one.
   - "updated": unedited venues whose folder changed since their last import, a new `geo:`
     included. On 2026-11-04: 0.
   - "unchanged": venues whose folder did not change since their last import. On 2026-11-04: 243.
   - "created": new folders. On 2026-11-04: the 4 Liechtenstein venues.
   - U + N + K is the number of folders that existed before the export, 243 on 2026-11-04.

   A `failed` line means stop: send it to the dev team. Keep the list of kept venues, because the
   import gives them no pin (step 7). Never use `--overwrite-cms-edits`: it would replace
   everything staff and managers changed on those venues.

   **The threshold.** Step 7 types at most K pins by hand: every kept folder has a `geo:`, and some
   kept venues may already have a pin from staff. If K is **more than 20**, do step 6 as usual,
   skip step 7 and ask the dev lead, who will have a command built that sets only their
   coordinates. Steps 8 to 10 go ahead, the CRM links included, because they don't depend on
   step 7.
6. **Import:** note the time, which is the point to restore to for an undo. Then run
   `sh bin/prod-import all`: import, media sync, purge. Expect the counts of step 5 without
   "Dry run".

   The 4 Liechtenstein venues now have no CRM company, but each already has one: the customer import
   of 2026-11-03 created it, linked to no venue. Do **not** run `sh bin/prod-import crm-bootstrap`,
   and do not press "Locations aus … Einträgen anlegen" on the CRM Locations list or "Location anlegen" in
   the CRM card of a venue in the CMS, before step 9 is done: each would create a second company
   for these venues.
7. **Pins for the kept venues, by hand,** at most 20 (step 5's threshold). For each folder in step
   5's list:
   1. Open `content/venues/<folder>/venue.yaml` and copy the two numbers under `geo:`, `lat` and
      `lng`. A folder without `geo:` has no pin to copy: skip it.
   2. In the back office, open Einträge, search the venue, open it and go to the card "Adresse &
      Lage". Look at "Breite" and "Länge" before typing:
      - **Both empty:** put `lat` into "Breite" and `lng` into "Länge", then save.
      - **Filled, with the note "Ortsmitte von …, auf der Website ohne Karte" below them:** that
        is a town centre, which shows no map. Replace it with the folder's two numbers and save.
      - **Filled, without that note:** staff set this pin. Leave it. If the venue's map shows the
        wrong place, step 8 fixes it.

      A save empties the page cache by itself.
8. **Check the possible wrong pins** with `content/owner/q3-pin-check.md`. For each venue:
   1. Open its page and press "Karte laden", or "Auf Google Maps öffnen". Compare the pin with the
      venue, for example with its website.
   2. A wrong pin: right-click the real building in Google Maps, and the first line of the menu
      holds the two numbers. Put them into "Adresse & Lage" as in step 7.
   3. If you don't know where the venue is, remove the pin: no map is better than a wrong one. How
      depends on «PLZ / Ort» in the same card:
      - **Empty** (most venues, which have only a canton): empty "Breite" and "Länge" and save.
      - **Filled:** press «Ortsmitte übernehmen». The CMS refuses empty fields for a venue with a
        town, saying "Bitte Breite und Länge eintragen oder «Ortsmitte übernehmen» wählen." A town
        centre shows no map, and the distance search then uses the town.

   The list explains the same steps.
9. **Link the 4 Liechtenstein venues to their companies, in the CRM, by hand.** The pairs are in
   `content/owner/46-crm-links.md` in the main checkout (git-ignored, real names stay out of the
   repo), one per line:
   - link venue `<LI venue 1>` to company `<LI company 1>`
   - link venue `<LI venue 2>` to company `<LI company 2>`
   - link venue `<LI venue 3>` to company `<LI company 3>`
   - link venue `<LI venue 4>` to company `<LI company 4>`

   For each pair: `/admin/crm/locations`, search the company's name, open it, then on its card
   "Einträge" press "Verknüpfen", type the venue's name or number, choose it and press
   "Verknüpfen". The toast says "Eintrag verknüpft.". A venue offered with "bei …" in its line
   already belongs to a company; stop and look before going on.

   Since #56 the card is "Eintrag" and the button "Zuordnen"; the steps are done on production.

   **Owner's check.** On `/admin/crm/locations`, a search by each company's name shows exactly one
   company, with 1 in the column "Einträge". Its page lists the venue under "Einträge", and the
   venue's CMS page shows that company in its CRM card. A search by each venue's name shows no
   second company. If the 4 were the only venues without a company, the button "Locations aus …
   Einträgen anlegen" is gone from the list.
10. **Check.**
    - **Pins.** Open five venue pages from different cantons, one of them in Liechtenstein. Each
      shows the map box with "Karte laden"; nothing loads from Google before the click. The click
      shows Google's map with the pin on the venue. A box with only "Auf Google Maps öffnen" means
      the server has no embed key: see "Before you start".
    - **Distance search.** `/hochzeitslocations?place=8001&radius=20` now lists venues around
      Zürich.
    - **Liechtenstein.** The 4 detail pages answer 200 and read "Liechtenstein", and `?canton=li`
      lists 4. They are in `sitemap-venues-*.xml`. `/hochzeitslocations/liechtenstein` is a 404
      until a fifth Liechtenstein venue exists.
    - **Keep:** `geocode-report-2026-11-02.csv`, `venue-yaml-before-q3.tgz` and `q3-pin-check.md`
      stay in the main checkout's `content/owner/`. Git ignores them.

**Undo.**
- **One wrong pin:** "Adresse & Lage", as in step 8.
- **Before step 6,** nothing has reached production. If step 3 changed any of the 243 folders,
  which it should not, `tar -xzf content/owner/venue-yaml-before-q3.tgz` puts their `venue.yaml`
  back as it was in step 2, `geo:` included.
- **The whole of step 6:** use Neon's restore to the time you noted (§Restore production), then
  `sh bin/prod-import purge`. It also undoes every other change made since then, so for a few pins
  prefer the back office.

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
   Not while the 4 Liechtenstein venues are unlinked, though (§Geocode the venues (Q3) and add
   Liechtenstein (#46), step 9). Since #56 the card is "Eintrag" and the button "Zuordnen"; the
   steps are done on production.
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
| `15 3 * * *` | `bin/cron-daily` | Inquiry purge, CRM stage refresh and renewal tasks, inquiry backfill, stuck-inquiry report, statistics ingest, cache pool prune, staging, session and limiter-lock sweeps |

The two cache jobs share a lock, `var/cache-clear.lock`. The midnight clear waits up to 60 s for it,
and the guard skips a tick when it is held.

**Time zone.** The lines assume the server runs on Zurich time. If the probe shows UTC, use the two
commented lines in `deploy/crontab.txt` in place of the 00:05 and 03:15 lines; the guard line stays.

**What a mail from cron means.** Hostpoint mails a job's output to the account, and a good night
prints nothing, so every mail is worth reading.

| The mail says | Meaning | What to do |
|---|---|---|
| `cron-daily: inquiries-purge failed`, or `pool-prune` | The retention promise in the privacy text is at risk. Often Neon was unreachable. | Run `sh bin/cron-daily` in the app directory. If it fails again, read the error in the mail. |
| `cron-daily: staging-sweep failed`, `sessions-sweep`, or `lock-sweep` | The sweep could not delete. `bin/cron-daily` creates its directories itself, so a missing directory is not the cause. | Read the step's own output in the mail, fix that, and run `sh bin/cron-daily` in the app directory |
| `cron-daily: crm-backfill failed`, with lines `<id>: <class>` | One inquiry could not be ingested. The line names the class only. | Nothing at first: the next night retries it. If the same id comes back, read the class and check that row. A `crm-backfill` line with a count only (`1 inquiries ingested, 0 failed.`) and no "failed" line is a repaired inquiry, not an error. |
| `cron-daily: stats-ingest failed` | The day files were not loaded. Often Neon was unreachable. Nothing is lost: the files stay in `var/stats/spool`. | Nothing at first: the next night or the Statistik button loads them. If it fails again, see [Statistik](#statistik). |
| `<id> venue <n> since <time> UTC` | An inquiry stayed `pending`: its process died, and the venue may have no mail | See below |
| `cron-midnight: the cache lock stayed busy for 60 s` | A guard run held the lock | If the next night is clean, nothing. A lock older than 10 minutes is taken over by itself. |

**A stuck inquiry.** Open `/admin/crm/anfragen/<id>` and press Erneut senden.

## Statistik

Built in #47. The site counts page views, listing impressions, contact clicks, Merkliste adds and
inquiries itself, with no cookie and no third party. Figures are on `/admin/statistik`, on each
location's Statistik tab and, for managers, in `/konto`.

- **Files.** The beacon (`POST /_stats`) appends to `var/stats/spool/<Y-m-d>.ndjson`, one line per
  event, and never touches Neon. The visitor-hash salt of each day is `var/stats/salt/<Y-m-d>`.
  Both live under `var/`, which a deploy keeps. `bin/cron-daily` creates the two directories.
- **Ingest.** The `stats-ingest` step of `bin/cron-daily` (03:15) runs `app:stats:ingest`. It
  renames each day file to `<Y-m-d>.<8 hex>.claimed`, loads it into `stats_events`, logs its name
  in `stats_ingest_files`, rebuilds `stats_daily` for the days it touched, and after the commit
  deletes the logged files and the salts of past days.
- **The button.** "Jetzt aktualisieren" on `/admin/statistik` runs the same job at once, today's
  file included. The flash names how many events it loaded, and the screens show "Stand" with the
  time of the last run. It wakes Neon like any `/admin` request.
- **After a crash.** A run that died leaves a `.claimed` file whose name is not in
  `stats_ingest_files`. The next run loads it. A run never loads a file twice, and the button and
  the cron cannot run at the same time (an advisory lock).
- **Rerun by hand.** In the app directory: `php bin/console app:stats:ingest`. It prints
  `<files> files, <events> events loaded, <skipped> lines skipped, <days> days rebuilt.` Skipped
  lines are lines that do not parse; they are not loaded and are deleted with their file.
- **Size.** Raw `stats_events` are kept forever. At 100k page views a month they grow by about
  0.5 GB a year, which costs under USD 0.20 a month per year of history on Neon Launch.

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
reveal, the Statistik refresh button, the 03:15 cron job and the nightly backup. The statistics
beacon does not: it writes a file.

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
