---
paths:
  - "src/Inquiries/**"
  - "templates/inquiries/**"
  - "assets/styles/inquiries/**"
  - "translations/inquiries*"
  - "tests/Support/Inquiries/**"
  - "tests/{Unit,Integration,Application}/Inquiries/**"
---
Append current-state lines for your cycle at the end (last commit).

### Inquiries

Inquiry form on the venue detail page, `inquiries` table, both mails, retention, `InquirySubmitted`. Read it by hand before touching the POST flow, a mail or code that reads `inquiries`.

## Data and POST flow

- **`inquiries`** (baseline migration): `venue_id` is `ON DELETE SET NULL`, so rows outlive venues; `venue_number`, `venue_name`, `venue_slug`, `recipient_email` (always the venue's address) are snapshots; `delivered_to` is the real recipient (differs under the override); outcomes are `MailStatus` (`pending|sent|failed|skipped`); the Anlass is `Occasion` (`occasion.<code>`). No IP or user agent is stored. `InquiryVocabularyChecksTest` pins the CHECK lists. The CRM never alters the table.
- **Route** `inquiry_submit` `POST /eventlokal/{slug}/anfrage`: 404 unknown or no address, 410 unpublished, history slug accepted. Checks in order: honeypot `website` (303, nothing stored), form (422), `FillTimeToken` (at least 3 s, no maximum age: pages are cached), limiter `inquiry_submit` (5 per hour per IP, 429 + `Retry-After`, only valid posts count). A failed venue mail answers 503. All responses `private, no-store`, `X-Robots-Tag: noindex, nofollow`, no cookie.
- **Double submit = one row**, server-side (no JS; PHP finishes cancelled POSTs): `SubmitInquiry` locks the `InquiryFingerprint` (HMAC of venue + normalised payload) with `pg_advisory_xact_lock` (transaction level only: `persistent: true` or a pooler would keep a session lock) and returns the newest row of the last 10 minutes as `SubmittedInquiry(isRepeat: true)`. `DeliverInquiry` claims the row (`FOR UPDATE` until commit, across both SMTP calls), so a repeat waits and gets the real outcome: `sent` sends nothing, `failed` and `pending` deliver again. A repeat is not published but costs a limiter token. The fingerprint is no person key: the CRM must not copy it.
- **Delivery** (own command: the row is committed before any SMTP call): venue mail, then the guest copy only if it went out. The venue mail catches `TransportExceptionInterface|RfcComplianceException|HandlerFailedException` (`Mailer::send()` unwraps only transport errors); anything else bubbles, row `pending`. The guest copy catches `\Throwable`: nothing may undo the record. A `sent` row is never mailed again, nor its `failed` copy. Gap: a crash between SMTP acknowledgement and commit re-mails the venue. `SubmitInquiry` refuses unpublished or address-less venues, guests outside 1-5000, past dates (Europe/Zurich).
- **CSRF** is stateless (`csrf.yaml`): `_token` is the constant `csrf-token`; the check is `Sec-Fetch-Site`, else `Origin`/`Referer` (none: refused), so the form fits a cached page. Tests send `HTTP_SEC_FETCH_SITE: same-origin`.

## Form, copy, mail

- `{{ inquiry_form(venue, form = null) }}` fills the detail page's `{% block inquiry %}`: nothing without an inquiry address, the confirmation on a signed `?anfrage=gesendet`, else the form; same HTML for everyone (S10). `#anfrage` is appended after `UriSigner::sign()`, which hashes fragments. `InquiryFormFactory::create(VenueDetail)` alone builds the form.
- **E-mail rule** (`InquiryData`): strict `Email`, then `EMAIL_SHAPE` (ASCII local part, no Unicode separator or control character, an IDN domain stays valid). The form may refuse more than `chk_inquiries_email`, never less (`InquiryEmailRuleTest`); Postgres' `\s` is Unicode-aware, PCRE's without `/u` is not, so never copy a CHECK regex into PHP untested on both.
- **Copy** is "du" (`PublicCopyAddressTest`): the guest copy greets "Danke, {name}!", the venue mail opens "Neue Anfrage für {venue}". Constraint messages: `validators` (`inquiry.<field>.<rule>`); the rest: `inquiries`.
- **Mail** (`InquiryMailer`): From `MAILER_FROM`; Reply-To is the guest (venue mail) or `%site.contact_email%` (guest copy). The context has no `email` key (`TemplatedEmail` reserves it, the renderer throws): the guest's address is `guest_email`. `INQUIRY_RECIPIENT_OVERRIDE` redirects the venue mail (original in `X-Inquiry-Original-Recipient`), never the guest copy, and all CRM mail; production must leave it empty (`ProductionRecipientOverrideTest`). Log lines put every value in a placeholder (the default `Logger` drops the rest of the context), never an exception message (it can quote an address).

## Event and retention

- **`InquirySubmitted`** (`inquiryId`): published by `InquiryEvents` from the controller after both commits, once per stored row, also on a failed venue mail; repeats and honeypot hits publish nothing. The outcome can change later: read `inquiries`, keep no copy. `InquiryEvents` catches `\Throwable` per dispatch and logs, so a subscriber cannot fail the guest; a throwing one stops the later subscribers, so each (CRM, Stats) catches its own.
- **Retention (LEG-2):** `app:inquiries:purge [--dry-run]`, `%inquiries.retention_months%` = 12 (in `/datenschutz`), `bin/cron-daily` step `inquiries-purge`. No purge event: `crm_inquiry_links` cascades and the CRM's `inquiry` entry holds no personal data, so the CRM must not copy any. `app:inquiries:report-stuck [--minutes=15]` (step `inquiries-stuck`) lists `pending` rows by id and venue number only, nothing when none, always exit 0 (cron mails its output).
- **Funnel (G8):** `DeliverInquiryHandler` (fifth constructor argument `MessageBusInterface`) records `inquiry_delivered` (`Shared\Command\RecordFunnelEvent` with the venue id or null, `DispatchAfterCurrentBusStamp`) right after `recordVenueMailSent`: once per inquiry (a `sent` row returns before), after the commit, so it cannot undo the record or 5xx the guest, and nothing for a failed venue mail. The row carries no address or name. G7 (`enquiry-quality`) must not emit it again; it adds the qualification the scorecard shows as "n/a" until then (`FunnelPaying::withQualifiedInquiry`). Tests: `tests/Application/Inquiries/SubmitInquiryFunnelTest`, the assertion in `SubmitInquiryDeliveryFailureTest`.

## Tests

- `InquiryFormFields::valid()` posts a back-dated fill-time token. `MailerInterface` is inlined in the test container: hand-build `InquiryMailer` with `RecordingMailer`. `SmtpSink` is a real SMTP server without SMTPUTF8 (Mailpit has it). `SubmitInquiryConcurrencyTest` forces the overlap of two POSTs with `pg_locks`. Each mail logs two mailer events: use `assertEmailCount()` or filter `!isQueued()`. A dead SMTP needs `MAILER_DSN` set before `createClient()`.
