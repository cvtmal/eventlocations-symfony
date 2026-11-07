---
paths:
  - "src/Seo/**"
  - "templates/seo/**"
  - "templates/bundles/**"
  - "assets/styles/seo/**"
  - "translations/seo*"
  - "tests/{Unit,Integration,Application}/Seo/**"
---
Append current-state lines for your cycle at the end (last commit).

### Seo

Holds page meta, JSON-LD, sitemaps, robots, `llms.txt`, the venues' Markdown views and the error pages; strings in `seo`. Read it before filling any page's `title`, `meta` or `canonical` block. Seo may use Search, Venues and Geo; nothing imports it, public templates call its Twig functions (`SeoExtension`).

## Page head contract: title, meta, canonical, hreflang, jsonld blocks

- **Blocks** (`base.html.twig`): `title`, `meta`, `canonical`, `hreflang` (default `shared/_hreflang.html.twig`: `de-CH` + `x-default`, none for unsafe requests; `i18n.md`), `jsonld` (default `site_jsonld()`; start an override with `parent()`).
- **Indexable page** (home, listing and landing, venue): `{% set seo = listing_seo(criteria, page, landing) %}` (`home_seo()`, `venue_seo(venue)`; a `PageMeta`) once at the top; `title` = `seo.title`; `meta` = `include('seo/_meta.html.twig', {seo, picture}, with_context = false)`; `canonical` = `<link rel="canonical" href="{{ seo.canonical }}">`; `jsonld` = `parent()` + builders. The listing prints `hreflang` (`query: {page: N}` from page 2) and `listing_jsonld(page)` only `if not seo.noindex`; the venue `canonical` block adds `<link rel="alternate" type="text/markdown">`.
- **Static** (Impressum, Datenschutz): `shared` title and description, `canonical` = `url(route)`. **Not indexable** (Merkliste, phone reveal): `<meta name="robots" content="noindex, nofollow">`, no canonical. **Near-me:** `noindex, follow` when `lat`, `lng` or `ort` is set, canonical the bare `url('nearby')`. Error pages and admin preview empty `hreflang` and `jsonld`.
- A new indexable page joins `PagesSitemap::urls()`; a path crawlers must skip joins `RobotsController::DISALLOW`; cache lifetimes and route guard tests: `module-shared.md`.

## Meta

- **Listing and landing:** `ListingMeta` canonicalises from the parsed criteria (`LandingPaths`): an unfiltered page N to itself (`?page=N`); filtered (a parameter beside `page` remains, or the facet came from the query) is `noindex, follow`, page-1 canonical, no hreflang, so `?canton=zh` canonicalises to `/hochzeitslocations/zuerich` (`ListingSeoTest`). `SearchCriteria` stays closed: add a `ListingMeta` method for a landing variant.
- **Share image (F6):** `seo/_meta.html.twig` falls back to `public/brand/og-default.png` (1200 × 630) for `og:image` and `twitter:image` when the page has no photo; `twitter:card` is always `summary_large_image`.
- **Home:** `og:type` `website`, bare canonical always. **Venue:** clean canonical; `og:type` `place` only with coordinates; `VenueFacts` (place facts nullable) drops town-centre coordinates (no `geo`).
- **Titles:** `TitleChain::fit()` takes the first pattern of at most 60 characters, else cuts the name at a word and keeps `{site}`; region text never reaches a title. `MetaDescription::fit()`: at most 155, ends at a sentence of 70+ characters.
- **Staff override:** `ListingMeta` reads `ContentBlocks` `landing.<prefix>.title|description` (description through `MetaDescription::fit()`) on every page that is not numbered, filtered or not; page >= 2 keeps the `title_page*` chain. `ListingMeta::defaults(LandingPage)` gives `{title, description}` without staff text for the editor's placeholders and search-result preview. `Seo\Extension\LandingPageCatalogue` (`LandingPageSource`) feeds the back office; Seo owns it because Search may not use Seo.

## JSON-LD

`JsonLdScript::render()` is the only renderer (`JSON_HEX_TAG`: a value cannot close the script); builders `venue_jsonld()`, `breadcrumb_jsonld(items)`; `SiteJsonLd`'s Organization has `logo` (`/brand/icon-512.png`, F6). `VenueJsonLd`: `["EventVenue","LocalBusiness"]` with `address` only with ZIP and town (Google requires it), else `EventVenue` with `containedInPlace` when the canton is known; nulls and empties are dropped, never a placeholder. **Never** `telephone` or `email` (DP-11), nor any rating or Google value (review-snippet rules, Maps terms), in JSON-LD, Markdown views or `llms.txt`.

## Sitemap, robots, llms.txt, Markdown

- **Sitemap** (`SitemapController`, unlocalized): `sitemap` `/sitemap.xml` is always an index; `sitemap_pages` `/sitemap-pages.xml` is `PagesSitemap::urls()`: home, listing, live landing pages (`lastmod` from `VenueSitemapQuery::newestChangeByGroup()`), near-me, then legal pages (no `lastmod`); `sitemap_venues` `/sitemap-venues-{n}.xml` holds 1000 URLs, 404 past the last. Only `loc`, `lastmod`, `image:image`.
- **robots** `robots` `/robots.txt` (`RobotsController`, body built in PHP: the raw-text scanner flags a template): literal `Disallow` list and a `Sitemap:` line with the request's scheme and host (behind a TLS proxy: `SYMFONY_TRUSTED_PROXIES`); keep it database-free (the uptime monitor polls it). **Pre-launch:** `seo.robots_block_google: true` (`config/services.yaml`) adds a `User-agent: Googlebot` + `Disallow: /` group (only Google; Googlebot ignores `*` once it has its own group), no meta `noindex`; at launch set it to false and drop the group from `RobotsTxtTest::test_the_exact_body`.
- **`llms_txt`** `/llms.txt` (`LlmsTxtController`): live `LandingPageCatalogue` entries and every venue of `VenueSitemapQuery::index()` (the sitemap's own `PUBLISHED`), so llms.txt, sitemap and `venue_show` 200s are one set (`LlmsTxtTest` requests every link). No `/llms-full.txt`: one uncached render per venue.
- **`venue_markdown`** `/hochzeitslocation/{slug}.md` (`VenueMarkdownController`; the slug requirement has no dot, so it never collides with `venue_show`) answers as `venue_show` (200, 301, 410, 404) with `text/markdown`, `X-Robots-Tag: noindex`, `Link: <html>; rel="canonical"`; not in the sitemap.
- **Escaping:** both Markdown templates run `{% autoescape false %}`; every value goes through `md`, `md_block` or `md_url` (`MarkdownExtension`; a plain URL stays bare for llms.txt readers), controllers through `MarkdownText::tidy()`.

## Error pages and footer

- `seo/_error_page.html.twig` (one shell; `error_icon` block: `map-pin` default, `landmark` 410, `info` generic) + `templates/bundles/TwigBundle/Exception/{error404, error410, error}.html.twig`. Body class `page-error`, never `error-page` (the wrapper: `ErrorPagesTest` reads its first link). They print no exception message and run no query, so the 500 page survives a database outage: `ErrorPagesQueryFreeTest` fails when anything in the layout, footer included, queries. `/admin` errors use them. Custom templates render only with debug off; dev preview `/_error/404`.
- `seo/_footer_cantons.html.twig` fills the footer (`footer_landing_cantons()`, `cache.landing_pages`); it never queries, a cold pool renders nothing.

## Canton wording

The 12 messages that print "Kanton {canton}" (`CantonWordingTest::messages()` lists them) select on `canton_code`, so Liechtenstein reads "in Liechtenstein". **A new caller must pass `canton_code`** beside `canton`: without it ICU prints a literal `{canton_code}` and no lint catches it. "in der Schweiz" in titles and the tagline stays.
