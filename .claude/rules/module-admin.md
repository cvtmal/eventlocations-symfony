---
paths:
  - "src/Admin/**"
  - "templates/admin/**"
  - "assets/styles/admin/**"
  - "assets/styles/admin.scss"
  - "assets/js/admin/**"
  - "admin-ui/**"
  - "config/packages/security.yaml"
  - "translations/admin*"
  - "tests/Support/Admin/**"
  - "tests/Fixtures/Admin/**"
  - "tests/{Unit,Integration,Application}/Admin/**"
---
Append current-state lines for your cycle at the end (last commit).

### Admin

Back office at `/admin` (S12): staff login, shell, extension points, audit, UI kit, Vue islands. Read it for any back-office work, Cms, Crm and Portal included.

## Data, login, session

- **Tables:** `admin_users` (`role` admin|editor, `is_active`: deactivate, never delete), `admin_audit_log` (append-only). `AdminUser` takes its id from the caller (`EntityId::generate()`) so `auditSubject()` knows it.
- **Dev admin:** `make seed` (`Story\AdminStory`: `EMAIL`, `PASSWORD`, `TOTP_SECRET`), credentials public on purpose, dev data only. `DevAutoLoginAuthenticator` serves `ADMIN=1`, inert unless `APP_ENV=dev`. Console: `app:admin:create-user --role=admin|editor`, `app:admin:reset-2fa`, `app:admin:reset-password`.
- **Firewalls:** `config/packages/security.yaml` is a hotspot (others use `#[IsGranted]`): `admin` (`^/admin(/|$)`) and `portal` (`/konto`), none elsewhere, so public pages stay session-free. First `access_control` match wins: the 2FA rule matches the code form and check path exactly; logout must stay reachable in the code step. `ROLE_ADMIN` includes `ROLE_EDITOR`.
- **Login:** form login (5 tries per 15 minutes), mandatory TOTP (`TotpSecretCipher`, `ADMIN_TOTP_KEY`), enrolment `/admin/2fa/einrichten` (`RequireTotpEnrolmentListener`), 5 wrong codes per user per 15 minutes (limiter `admin_totp`, `TotpThrottleListener`). `RefuseInactiveAdminListener` (`CheckPassportEvent`) refuses a deactivated account after the password check: `checkPreAuth` would disclose it, `checkPostAuth` never runs for a TOTP user.
- **Session:** cookie `SameSite=Strict`, `HttpOnly`, path `/admin`; 30-day sliding idle timeout (`IdleTimeoutListener::IDLE_SECONDS`). `AdminSessionCookieListener` re-sends a 30-day cookie only for a fully logged-in session (others stay browser-session), expiring by `time()`, not the clock (tests: `self::mockTime()`). `framework.session.gc_maxlifetime` and `bin/cron-daily`'s `sessions-sweep` must not go below `IDLE_SECONDS`. `/admin` is `no-store, private` and `noindex, nofollow` (`AdminResponseHeadersListener`).
- **"Is this the back office?"** asks `Security\AdminArea` (`contains()`, `isJson()`), never the raw path: router and firewall decode it. `AdminIdentity` (per request, `AdminUserProvider`) keeps fingerprints, not the hash or secret, in the session, so a password, role or 2FA change ends that user's sessions (`SessionEndsOnChangeTest`).

## Routes and JSON

- **Routes:** `admin_dashboard` `/admin`, `admin_login`, `admin_totp_enrol`, `admin_users*` `/admin/benutzer...`, `admin_audit_log` `/admin/audit-log` (filters `actor`, `actor_type`, `subject_type`, `subject_id`, `from`, `to`), `admin_styleguide` `/admin/_styleguide` (dev/test); `admin_2fa_login`/`admin_2fa_check` live in `config/routes/scheb_2fa.yaml`.
- **New route:** `options: [LocalizedRoutes::UNLOCALIZED => true]`, a name starting `admin_`, `#[IsGranted]`. `AdminRoutesAreProtectedTest` walks every route per login state, filling parameters by requirement (else a UUID): another strict requirement 404s and fails until `ChecksAdminRoutes::routeSample()` knows it. `_logout_admin` is POST only (`config/routes/security.yaml`).
- **JSON** `/admin/api/<module>/...` (names `admin_api_<module>_...`): copy `SearchController`'s class attributes (`#[IsGranted]`, `#[IsCsrfTokenValid('admin_api', ...)]`); 422 answers `{"errors": {"field": ["message"]}}` (`ValidationErrors`); 401 and 403 are JSON too. Module-less: `admin_api_search` (`GET ?q=`, editor, no CSRF; `q` trimmed and cut to 100; under 2 characters, invalid UTF-8, a NUL or an array give no groups), `admin_api_markdown_preview` (`POST {"text"}` up to 20000, answers `{"html"}`). `adminFetch` (`admin-ui/src/api.js`) treats only a redirect, 401 or 2xx non-JSON as a lost session, never a 413, 500 or 504.

## Audit and extension points

- **Audit:** a command implements `Shared\Messenger\AuditableCommand` (`auditSubject()`, `auditSummary()`, never a password, secret or token); `Admin\Messenger\AuditLogMiddleware` writes the row in the transaction. `AuditLogQuery::forSubject()`/`forSubjects()` read it back ("Zuletzt bearbeitet von", CRM timeline).
- **Extension points** (`Admin\Extension`, fakes in `tests/Fixtures/Admin/`): implement `NavigationItem`, `DashboardWidget`, `RecordTab`, `RecordPanel`, `SearchProvider` or `TimelineActivityType` in your module's `Extension` layer; autoconfiguration registers it. Twig `admin_navigation()`, `admin_dashboard_widgets()`, `admin_record_tabs()`, `admin_record_panels()` filter by `requiredRole()` and sort by `priority()` descending.
  - `NavigationItem::badge(): ?int` has one tone, pink (`.sidebar__badge`); a second tone changes this extension point. `SearchProvider` results are capped at 5 per provider.
  - `TimelineActivityType` (tag `admin.timeline_activity_type`) feeds `TimelineActivityTypeRegistry` and `Crm\TimelineProps`; `composable()` is false for kinds only the system writes; `isComposable()` ignores the role (a handler has no session), so the controller uses `composableCodes()`. Browser side: `admin-ui/src/timeline/types.js`; adding a kind: `.claude/rules/module-crm.md`.

## Shell, components, forms

- **Shell:** `templates/admin/base.html.twig` (hotspot: fill the blocks) has `title`, `body_class`, `breadcrumbs`, `page_actions`, `page_header`, `main`, `aside`, `scripts`. Flashes are `success`/`error` toasts with an `admin` catalogue key.
- **Styles:** `assets/styles/admin.scss` (append one `@use` per partial; never loaded with `app.css`). `--a-accent-bright` (#9da3ff since F6) is for icons and the login bar on dark grounds only (`--a-accent` carries white text; 5.81:1 on #2f2f2f, 7.48:1 on #1b1b1b). Input borders use `--a-field-strong` (3:1, `AdminInputContrastTest`).
- **Components** (`templates/admin/components/`; docblocks state params; strings arrive translated): atoms by `include ... with_context = false`, shells (`_card`, `_table`, `_modal`...) by `embed ... only`; not `templates/components/`. `_tier_badge` is a Twig map of the `Tier` values, so Admin stays free of Venues.
- **Forms:** `{% form_theme form 'admin/form/theme.html.twig' %}` per template, never `twig.form_themes` (public forms must not inherit it). A counter is `attr: {'data-counter-max': n}`, not `maxlength`; money fields need `html5: true`; `required` shows `*` (add `_required_hint`). `Admin\Form\ComboboxType` (`url`, `static_options`, `display_value`, `free_text`; the last two together throw) and `MarkdownType` (preview via `admin_api_markdown_preview`).
- **Twig:** `admin_island(name, props, fallback = '')` (fallback unescaped), `admin_island_deferred` (loads nothing until first use), `admin_island_assets(nonce)` (closes `base.html.twig`), `admin_query_path(changes)`, `admin_page_links(page, last)`.
- **Vanilla scripts:** a new `assets/js/admin/*.js` goes into `components/_scripts.html.twig` (every page, not `{% block scripts %}`; `AdminAssetBudgetTest` globs the folder). They delegate at document level, so island markup works too.

## Vue islands

- **Add one:** a line in `admin-ui/src/islands.js` (hotspot), the file `admin-ui/src/islands/<Pascal>Island.vue` (`<script setup>`, no `<style>`, no bare strings).
- **Kit:** `admin-ui/src/components/Ui*.vue`, imported only by islands, same classes and markup as the Twig twins (parity: `admin-ui/tests/fixtures/markup/`). ESLint forbids `<style>` and static inline styles. Plain forms double-POST on a double click; only islands guard (`saving`).
- **Guards:** `AdminAssetBudgetTest` (JS 60 KB gzip shared, 30 KB per island; CSS 30 KB); `PublicPagesLoadNoAdminAssetsTest` (no admin marker on public pages, no `admin_*` call in public templates, no `--a-*` token in the public CSS). `MarkdownRenderer`'s options are security settings.
