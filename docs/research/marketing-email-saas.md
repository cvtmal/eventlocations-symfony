# Marketing email: which SaaS for CRM campaigns

Research for the lead (-9c), 2026-11-03. Research only: no accounts, no code. **Prices change.** Every
price below was read on 2026-11-03 unless a source carries its own date. Prices exclude VAT.
"Unverified" means no primary source confirmed it.

**Exchange rates.** ECB reference rates of 2026-11-02 (the 3 Nov fix was not out yet):
1 EUR = 0.9193 CHF and 1 EUR = 1.1216 USD, so 1 USD ≈ 0.8196 CHF
([ECB](https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml)). The SNB's October 2026
averages are EUR 0.92841 and USD 0.82854 ([SNB](https://data.snb.ch/api/cube/devkum/data/csv/en)).
Where a vendor bills in CHF, its own figure is shown, not a conversion.

## TL;DR

- **Don't send campaigns through Hostpoint.** Its AGB allow newsletters only with opt-in and opt-out,
  and they reserve limits. Its old manual names 1,000 mails per address per 24 h. Worse, campaigns
  would share the domain and the shared SMTP pool with inquiry and portal mail. A spam complaint
  would then hurt the mail that matters.
- **Model (a): the vendor holds the list.** Staff design and send in the vendor's UI. The app only
  pushes contacts. The vendor handles double opt-in, unsubscribes, bounces, complaints and one-click
  unsubscribe. That meets UWG Art. 3 lit. o without privacy machinery in the app.
- **Recommendation: Brevo Standard**, about **CHF 28/month at 1k contacts and CHF 54 at 5k** (CHF 68
  at 20k). It is EU-hosted (FR) and has a German UI. Of all the vendors it has the best API for
  "push contacts only": a native double-opt-in endpoint plus unsubscribe, bounce and spam webhooks.
- **Runner-up: CleverReach Pro**, about **€24 / €72** at 1k / 5k (CHF 22 / 66), €192 (CHF 177) at
  20k. It is German and hosted in Germany, with double opt-in by default. Its API has no bounce or
  complaint webhooks, and its per-tier prices are confirmed only by third parties.
- **Integration:** a nightly `app:crm:newsletter-sync` in `bin/cron-daily` upserts the changed
  contacts and pulls back unsubscribes. No public webhook route, no consent table. One small field
  says which contacts may be pushed.
- **DNS:** authenticate `news.hochzeitslocation.ch` at Brevo: a code TXT record plus 2 DKIM CNAMEs
  in the Hostpoint zone. Leave the apex SPF, DKIM and DMARC as they are.

## 1. Today's setup (checked 2026-11-03)

- **Transport.** Symfony Mailer sends with `MAILER_DSN` to `asmtp.mail.hostpoint.ch:587`
  (STARTTLS) as `hallo@hochzeitslocation.ch` (`deploy/env.local.example:25`, `docs/operations.md`
  §Mail). `MAILER_FROM` is `Hochzeitslocation <hallo@hochzeitslocation.ch>` (`.env:62`,
  `config/packages/mailer.yaml`). This one path carries the inquiry mails, the portal's signed links
  and the CRM's 1:1 mails (`email_sent` activities, `crm_email_templates`).
- **DNS**, checked with `dig @1.1.1.1` today. Nameservers are `ns{,2,3}.hostpoint.ch`. MX is
  `mx1`/`mx2.mail.hostpoint.ch`.
  - SPF: `v=spf1 redirect=spf.mail.hostpoint.ch`.
  - DKIM: selectors `20261101-{ed25519,rsa2048,rsa1024}-d6dd01da._domainkey` are published.
  - DMARC: `v=DMARC1; p=none; rua=mailto:hallo@hochzeitslocation.ch`, with no `sp=` tag, so
    subdomains inherit `p=none`.
  - Apex, `www` and a wildcard all point to `217.26.50.72`, so `news.hochzeitslocation.ch` already
    resolves (to the web server). No TXT wildcard.
- **Hosting account.** `djweddin` is a shared Hostpoint account with many live sites, so the SMTP
  reputation is shared with them as well.
- **Data.** `crm_contacts` has `email` (unique, lower-case) and `kind` (`venue_contact`, `couple`,
  `other`). It has **no consent or newsletter field**.

## 2. Hostpoint for bulk mail: limits, policy, risk

**Policy: newsletters are allowed, spam is not.** The Webhosting AGB, Anhang 1 Benutzungsrichtlinien
(August 2023), Ziff. 3.2, say: "Der Versand von identischen E-Mails an eine Vielzahl von Adressaten
ist verboten, sofern dieser ohne vorgängige Einwilligung des Adressaten (Opt-in), ohne korrekte
Angabe der Identität des Absenders oder ohne Hinweis auf eine einfache und kostenlose
Ablehnungsmöglichkeit (Opt-out) erfolgt". The clause repeats UWG's existing-customer exception.
Other clauses add to it:

- Ziff. 4.1 treats "Massensendungen" that overload systems as a security breach.
- Main AGB Ziff. 2.2.2–2.2.4: Hostpoint "kann jederzeit Grenzwerte … festlegen" (fair use). The offer
  is meant for private use and SMEs. Hostpoint may lock the account if operations suffer.

([AGB PDF](https://www.hostpoint.ch/downloads/pdf/de_AGB-Webhosting.pdf))

**Limits: refuted as a hard, published number today, confirmed as a historical default.**

- The Control Panel manual v2.5 (January 2016), p. 18, says: "Standardmässig können pro
  E-Mailadresse innert 24 Stunden 1000 E-Mail versendet werden." It mentions a 24 h "unlimitiert"
  switch for newsletter use, with a warning to mind the AGB
  ([Anleitung.pdf](https://admin.hostpoint.ch/documents/Anleitung.pdf)).
- **Unverified:** whether 1,000/24 h still applies, and any cap on recipients per message. No current
  support page states either.
- Confirmed today: external SMTP must use port 587 with STARTTLS, and the maximum mail size is 50 MB
  ([support](https://support.hostpoint.ch/de/produkte/webhosting/haeufig-gestellte-fragen/e-mail-versand-ueber-die-webseite),
  [size](https://support.hostpoint.ch/de/technisches/e-mail/allgemeines-zu-e-mail/maximale-e-mail-groesse)).
- Hostpoint offers Mailman lists but no newsletter tool
  ([support](https://support.hostpoint.ch/de/technisches/e-mail/mailinglisten/was-ist-eine-mailingliste)).
- One support ticket would settle the open points. It is not needed for the decision.

**Deliverability risk to transactional mail: confirmed.**

- **Shared IP.** Google: "The activity of any senders using a shared IP address affects the
  reputation of all senders for that shared IP address". Google also recommends one IP for
  notifications and another for promotions
  ([Google sender guidelines](https://support.google.com/a/answer/81126)). On Hostpoint we control
  neither the IP nor the other tenants.
- **Domain reputation.** Gmail rates the domain as well. A campaign from `hochzeitslocation.ch` that
  draws complaints (> 0.3 % spam rate, target < 0.1 %) lowers the standing of the inquiry and portal
  mails sent from the same domain.
- **Enforcement.** Gmail has rejected non-compliant traffic more strictly since November 2025
  ([FAQ](https://support.google.com/a/answer/14229414)).
- **Bulk-sender rules** apply to more than 5,000 mails a day per *primary* domain, and a subdomain
  counts toward its parent: SPF + DKIM, DMARC (`p=none` is enough) with alignment, RFC 8058 one-click
  unsubscribe for marketing, and unsubscribes honoured within 2 days. Sources:
  [Google](https://support.google.com/a/answer/81126),
  [Yahoo](https://senders.yahooinc.com/best-practices/),
  [Microsoft, from 5 May 2025](https://techcommunity.microsoft.com/blog/defenderforoffice365blog/strengthening-email-ecosystem-outlook%E2%80%99s-new-requirements-for-high%E2%80%90volume-senders/4399730).
  Hostpoint SMTP plus Symfony Mailer would need us to add RFC 8058 ourselves. Every ESP below does it.
- **Industry practice.** Separate marketing and transactional mail by subdomain and by IP pool. See
  [Postmark](https://postmarkapp.com/message-streams) ("do not mix … including IP ranges") and
  [SendGrid](https://www.twilio.com/docs/sendgrid/onboarding/email-api/evaluate-and-plan-your-strategy).

**Conclusion.** Keep transactional and 1:1 CRM mail on Hostpoint, which is fine at our volume. Send
campaigns through an ESP from `news.hochzeitslocation.ch`.

## 3. The law: UWG Art. 3 Abs. 1 lit. o

[SR 241, as of 1 January 2025](https://www.fedlex.admin.ch/eli/cc/1988/223_223_223/de): unlauter
handelt, wer "Massenwerbung ohne direkten Zusammenhang mit einem angeforderten Inhalt
fernmeldetechnisch sendet … und es dabei unterlässt, vorher die Einwilligung der Kunden einzuholen,
den korrekten Absender anzugeben oder auf eine problemlose und kostenlose Ablehnungsmöglichkeit
hinzuweisen; wer beim Verkauf von Waren, Werken oder Leistungen Kontaktinformationen von Kunden
erhält und dabei auf die Ablehnungsmöglichkeit hinweist, handelt nicht unlauter, wenn er diesen
Kunden ohne deren Einwilligung Massenwerbung für eigene ähnliche Waren, Werke oder Leistungen
sendet".

The rule has four parts:

1. **Prior consent** is required.
2. **The sender must be named correctly.**
3. **Every mail needs an easy, free opt-out.**
4. **Existing-customer exception:** no consent is needed for our *own similar* services, if the
   contact came from a sale *and* was told about the opt-out at that moment.

What that means for our contacts:

- **B2B is covered as well.** The text says "Kunden" with no consumer limit, and the
  [CSA comparison](https://certified-senders.org/wp-content/uploads/2017/07/The-legal-situation-for-email-marketing-in-Germany-Austria-and-Switzerland.pdf)
  marks it "b2b and b2c".
- **Paying venue customers** (a listing tier or renewal) can rely on the exception for listing
  news, if the order or invoice mail mentions the opt-out. That is an assumption about how the venue
  contracts are set up, not checked.
- **Couples and other contacts** need consent. The vendor's double opt-in provides it and records
  the proof.

**nDSG.** It adds little here. An EU vendor needs no transfer check. A US vendor is fine if it is
certified under the Swiss-US DPF, which has been adequate since 15 September 2024
([Federal Council](https://www.admin.ch/gov/de/start/dokumentation/medienmitteilungen/bundesrat.msg-id-102054.html)).

**The bar for "no privacy machinery in the app".** The vendor runs the double opt-in, the
unsubscribe and preference page, the suppression of bounces and complaints, and RFC 8058. The app
only decides who gets pushed, and to which list.

## 4. Vendor comparison

Costs assume about **2 campaigns a month to the whole list**. The CHF figures in brackets are
conversions at the ECB rates above. Brevo's CHF figures are its own.

### Price

| Vendor | Plan | 1k contacts | 5k contacts | 20k (side) | Billed by |
|---|---|---|---|---|---|
| **Brevo** | Standard | **CHF 28** (€29) | **CHF 54** (€55) | CHF 68 (€69) | Mails/month; each tier caps contacts (10k mails ≤ 1.5k contacts; 20k+ mails = no cap) |
| Brevo | Starter | CHF 14 (+ CHF 9 to remove the logo) | CHF 28 (+9) | CHF 40 (+9) | Same as Standard |
| **CleverReach** | Pro | €24 (CHF 22) | €72 (CHF 66) | €192 (CHF 177) | Recipients, unlimited sends |
| CleverReach | Basic | €20 (CHF 18) | €60 (CHF 55) | not available | Max 5k contacts, 4 mails each a month |
| Mailjet | Essential | €18 (CHF 17) | €18 (CHF 17) | €37 (CHF 34) | Mails/month, unlimited contacts |
| MailerLite | Comfort | €17 (CHF 16) | €44 (CHF 40) | €139 (CHF 128) | Subscribers |
| Mailchimp | Essentials | about $20–30, unverified | $75 (CHF 61) | about $250+, unverified | Contacts; **unsubscribed contacts count** |
| rapidmail | Essential | €19 (CHF 17) | €52 (CHF 48) | about €190 (CHF 175) | Recipients |
| Resend | Marketing | $0 | $40 (CHF 33) | $180 (CHF 148), 25k tier | Contacts |
| Postmark | Basic/Pro (Broadcast) | $15 (CHF 12) | $15 (CHF 12) | about $56 (CHF 45) | Mails sent |
| Amazon SES | Pay as you go | about $0.20 | about $1 | about $4 | $0.10 per 1,000 mails |
| mailXpert (CH) | LITE + API / KMU | about CHF 172 | about CHF 172 | about CHF 220 | Yearly mail packages, plus CHF 390 setup |

Price sources:

- [Brevo pricing API](https://pricing-backend.brevo.com/public/pricing/api/plans/all), which feeds
  [brevo.com/pricing](https://www.brevo.com/pricing/). I checked it on 2026-11-03: Standard base
  CHF 15, plus CHF 13 for the 10k tier, 39 for 20k and 53 for 40k.
- [CleverReach](https://www.cleverreach.com/de-de/preise/) shows only "from" prices. The tiers come
  from [EmailToolTester, 2025-06-13](https://www.emailtooltester.com/de/newsletter-tools/cleverreach/preise/).
- [Mailjet](https://www.mailjet.com/pricing/), [MailerLite](https://www.mailerlite.com/pricing).
- Mailchimp: [EmailToolTester, 2026-08-05](https://www.emailtooltester.com/en/reviews/mailchimp/pricing/)
  and [Mailchimp's help on what counts](https://mailchimp.com/help/about-mailchimp-pricing-plans/).
- rapidmail: [EmailToolTester, 2026-03-30](https://www.emailtooltester.com/de/newsletter-tools/rapidmail/kosten).
- [Resend](https://resend.com/pricing), [Postmark](https://postmarkapp.com/pricing),
  [SES](https://aws.amazon.com/ses/pricing/), [mailXpert](https://mailxpert.ch/preise/abo).
- Yearly billing saves about 10 % at Brevo, CleverReach, Mailjet and MailerLite.

### Fit

| | Brevo | CleverReach | Mailjet | MailerLite | Mailchimp |
|---|---|---|---|---|---|
| Double opt-in by the vendor, via API | **Yes, native endpoint** ([doc](https://developers.brevo.com/reference/create-doi-contact)) | Yes, the default ([pricing](https://www.cleverreach.com/de-de/preise/)) | No, only in hosted forms ([blog](https://www.mailjet.com/blog/how-to-set-up-a-double-opt-via-mailjets-api/)) | Account switch "DOI for API" ([help](https://www.mailerlite.com/help/how-to-use-double-opt-in-when-collecting-subscribers)) | Status `pending`; unverified that it mails |
| Unsubscribe page, bounce and complaint suppression | Yes | Unsubscribe yes, the rest unverified | Yes | Yes | Yes |
| Webhooks | `unsubscribe`, `hard_bounce`, `spam`, … ([doc](https://developers.brevo.com/docs/marketing-webhooks)) | `receiver.unsubscribed`; **no bounce or complaint events** ([doc](https://developers.cleverreach.com/docs/guides/webhooks/events/)) | `unsub`, `bounce`, `spam`, `blocked` ([doc](https://dev.mailjet.com/email/guides/webhooks/)) | `subscriber.unsubscribed`, `.bounced`, `.spam_reported`, HMAC-signed ([doc](https://developers.mailerlite.com/docs/webhooks.html)) | `unsubscribe`, `cleaned`, … ([doc](https://mailchimp.com/developer/marketing/api/list-webhooks/)) |
| PHP and Symfony | `getbrevo/brevo-php` v6 (Oct 2026); `symfony/brevo-mailer` (transactional only); contacts API 10 req/s ([limits](https://developers.brevo.com/docs/api-limits)) | Official SDK, REST v3 with OAuth2; no bridge | Official SDK; `symfony/mailjet-mailer` | `mailerlite/mailerlite-php` (Sep 2026); upsert plus group import; 120 req/min | `mailchimp/marketing` SDK has had **no release since 2022**; the bridge is Mandrill (paid, transactional) |
| Editor for non-developers | Drag and drop | Drag and drop | Drag and drop | Drag and drop | Drag and drop |
| German UI and support | UI yes ([brevo.com/de](https://www.brevo.com/de/)); German support unverified, phone only on Professional | **Yes / yes**; phone €22.95/month extra | **Yes / yes** ([support](https://www.mailjet.com/support)) | **No German UI** ([help](https://support.mailerlite.com/en/articles/7961038)) | Chat and email in German; UI unverified |
| Data location | **EU** (FR; own servers plus Google Cloud) ([help](https://help.brevo.com/hc/en-us/articles/360001005510)) | **DE / EU** ([data security](https://www.cleverreach.com/en/data-security/)) | **EU** (Google Cloud, Frankfurt and Belgium) ([legal](https://www.mailjet.com/legal/security-privacy/)) | **EU** (DE / NL) | **US** (DPF plus SCC, no EU option) ([DPA](https://mailchimp.com/legal/data-processing-addendum/)) |

**Deliverability.** EmailToolTester's last published round (January 2024, shared IPs) scored
MailerLite 89.8 %, Mailchimp 89.5 %, Brevo 88.3 % and Mailjet 88.0 %. That is effectively a tie at
our volume. CleverReach and rapidmail have no independent score. CleverReach is a long-standing CSA
member: unverified.
([test](https://www.emailtooltester.com/en/email-deliverability-test/)). On a shared pool, our own
list hygiene (double opt-in, no bought addresses) matters more than the vendor. A dedicated IP is
pointless below about 100k mails a month.

**The rest, briefly:**

- **Amazon SES** has the only Swiss region (Zurich `eu-central-2`, API only, no SMTP) and costs
  almost nothing. It offers one contact list, a hosted unsubscribe page with RFC 8058, and a
  suppression list. It has **no campaigns, no editor and no double opt-in**. The app would loop over
  `SendEmail` itself, after a 200-mails-a-day sandbox exit
  ([list management](https://docs.aws.amazon.com/ses/latest/dg/sending-email-list-management.html),
  [endpoints](https://docs.aws.amazon.com/general/latest/gr/ses.html)).
- **Postmark** has no contact store and says it offers no campaign features
  ([message streams](https://postmarkapp.com/message-streams)). It is US-only.
- **Resend** has a no-code broadcast editor and hosted unsubscribe. Data is stored in the US, the UI
  is English-only, and no double opt-in was found ([GDPR](https://resend.com/security/gdpr)).
- **mailXpert / Swiss Newsletter** is the Swiss-hosted option, with phone support. It costs about 3×
  Brevo, the API is an add-on on LITE, and its API and webhook docs are unverified.
- **rapidmail** is a credible German alternative to CleverReach: about the same price, German, servers
  in Germany. Its webhooks are unverified.

## 5. Model (a) vendor list vs. (b) our own rendering through a raw sending API

| | (a) Vendor holds the list, app syncs contacts | (b) App renders and sends through SES, Postmark or Resend |
|---|---|---|
| Campaign design | Staff use the vendor's drag-and-drop editor | We build an editor, or staff write HTML |
| UWG duties (double opt-in, opt-out, proof) | Vendor | **App**: double-opt-in flow, token pages, consent log, unsubscribe route, RFC 8058 |
| Bounces and complaints | Vendor suppresses them | App consumes SNS or webhooks and keeps a suppression list |
| Sending a campaign | One click in the vendor UI | A batch job, throttling, retries, progress, reporting |
| Monthly cost | CHF 28–54 | Under CHF 15 |
| Build effort | About 1 small cycle | Several cycles, plus upkeep |

**Recommendation: (a).** With 2 staff users and 1–5k contacts, option (b) saves about CHF 30 a
month. In exchange, it rebuilds the exact machinery the owner does not want in the app. Option (b)
only pays off at large volume or for fully automated per-user mailings, and neither is the case here.

## 6. Recommendation

**Brevo Standard, model (a).** About CHF 28/month at 1k contacts and CHF 54 at 5k (CHF 68 at 20k);
yearly billing is −10 %. Starter is CHF 23 / 37 with the logo removal, but it lacks Standard's
A/B tests and send-time features. Start on Starter if cost matters, and upgrade without migrating.
Brevo wins because:

- It is the only vendor with a **native double-opt-in API**. Contacts without consent can be pushed
  into the double-opt-in flow, and Brevo mails the confirmation and stores the proof.
- It has full `unsubscribe`, `hard_bounce` and `spam` events, plus a contacts API that reports
  blocklisted contacts.
- It is EU-hosted, has a German UI and a maintained PHP SDK, and covers the whole 1–20k range
  without a jump.

**Watch:** at 1.5k contacts the tier rule forces the 15k-mail tier (CHF 40), and at 2.5k it forces
the 20k tier (CHF 54).

**Runner-up: CleverReach Pro** (€24 / €72, CHF 22 / 66). It is German through and through, the
better pick if the owner values a German company and German phone support. It sends no bounce or
complaint webhooks, which is fine under model (a) because the vendor suppresses them anyway. Verify
the tier prices at sign-up.

**Not recommended:**

- Mailchimp: the most expensive per contact, unsubscribed contacts are billed, data sits in the US,
  and its PHP SDK is stale.
- MailerLite: no German UI.
- Mailjet: the cheapest, but no double opt-in through the API.

### Integration in Crm (sketch for a later cycle)

- **Who may be pushed.** Add one column on `crm_contacts`, for example
  `newsletter VARCHAR(20) NULL CHECK IN ('customer','opt_in_requested')`, set in the contact form:
  - `customer`: a paying venue contact under the existing-customer exception. Pushed straight to
    the list "Kunden".
  - `opt_in_requested`: anyone else. Pushed through Brevo's double-opt-in endpoint. Brevo adds the
    contact to the list "Newsletter" only once confirmed.
  - `NULL`: never pushed.

  This is the only "consent" data the app keeps. The proof lives at Brevo.
- **Sync: a nightly cron, not on every change.** Add `app:crm:newsletter-sync` to `bin/cron-daily`,
  which already wakes Neon once at 03:15. It does three things:
  1. Upserts the contacts whose `updated_at` is later than the last run, with attributes `VORNAME`,
     `NACHNAME`, `FIRMA` and `KIND`.
  2. Removes contacts whose flag was cleared.
  3. Pulls the contacts Brevo blocklisted (unsubscribed, hard bounce, spam) since the last run. For
     each one it clears the flag and logs a timeline activity.

  Campaigns are planned days ahead, so a nightly lag costs nothing. Polling avoids a public webhook
  route, its secret and its tests. A "Jetzt synchronisieren" button can run the same command if
  staff ask for it.
- **Erase.** The existing contact erase (`ROLE_ADMIN`) also calls Brevo's delete-contact endpoint
  synchronously, so an erasure leaves no copy behind.
- **Client.** Use `symfony/http-client`, which is already a dependency, against about 4 endpoints
  (contact upsert or batch import, double-opt-in create, delete, list blocklisted contacts). That
  avoids pulling in the generated SDK. `BREVO_API_KEY` goes in `.env.local`. The client sits in
  Crm's layers, outside every public module.
- **Not needed:** `symfony/brevo-mailer` (transactional mail stays on Hostpoint), a webhook
  controller, a consent log, an unsubscribe page.

### DNS on Hostpoint

The zone is edited by the Hostpoint admin or in the panel, as with DKIM in November.

1. In Brevo, add the sender domain **`news.hochzeitslocation.ch`** and copy the records its wizard
   shows ([help](https://help.brevo.com/hc/en-us/articles/12163873383186)): a `brevo-code` TXT for
   verification and **2 DKIM CNAMEs** (`brevo1._domainkey.news`, `brevo2._domainkey.news`, names as
   shown there).
2. Add those records in the Hostpoint zone of `hochzeitslocation.ch`, as TXT and CNAME rows under
   the host `news`. Specific names beat the wildcard `*`, so the existing wildcard A record does not
   get in the way.
3. **DMARC:** nothing to add. The apex record `p=none` without `sp=` covers `news`, and Brevo's DKIM
   aligns on the subdomain. Raise both to `p=quarantine` later, once the `rua` reports show only
   passing mail.
4. **SPF:** the apex record stays untouched. Brevo uses its own bounce domain, so SPF alignment is
   not needed (DKIM suffices).
5. **Sender:** From `Hochzeitslocation <hallo@news.hochzeitslocation.ch>`, Reply-To
   `hallo@hochzeitslocation.ch`, so replies land in the existing mailbox.
6. Optional: add the domain to [Google Postmaster Tools](https://postmaster.google.com/) to watch the
   spam rate.

## Open points for the owner

- Which contacts count as "customers" for the exception: venues with a paid listing? Does their
  order or invoice mail mention the opt-out? If not, add one line to it.
- Brevo or CleverReach: is a German company with German phone support worth about CHF 10–15 a month
  more at 5k contacts?
- Hostpoint's current sending limit: ask support only if campaigns would ever go through Hostpoint,
  which this report advises against.
