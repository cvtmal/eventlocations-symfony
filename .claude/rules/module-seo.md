---
paths:
  - "src/Seo/**"
  - "templates/seo/**"
  - "templates/bundles/**"
  - "assets/styles/seo/**"
  - "translations/seo*"
  - "tests/{Unit,Integration,Application}/Seo/**"
---

### Seo

Sitemap, robots, JSON-LD builders, landing page definitions.

**Built in #16 (`seo-foundation`).** No migration, no new package. Search and Venues never depend on Seo in PHP: their templates call Seo's Twig functions.

- **Meta:** `listing_seo(criteria, page)` (`Seo\ListingMeta`) and `venue_seo(venue)` (`Seo\VenueMeta`) return `Seo\ValueObject\PageMeta` (`title`, `description`, `canonical`, `noindex`, `ogType`, `latitude`, `longitude`); set it once at the top of a page and fill `title`, `meta`, `canonical`, `hreflang`, `jsonld`. `templates/seo/_meta.html.twig` (`seo`, `picture` ?Picture; `with_context = false`) renders description, robots, Open Graph and Twitter tags. "Filtered" is `[] !== $criteria->withPage(1)->toQuery()`, so every URL shape follows from the parsed criteria: a filter state is `noindex, follow`, canonicalises to the clean path whatever its page and has no hreflang; an unfiltered page N canonicalises to itself. `SearchCriteria` stays closed: add a method to `ListingMeta` for a landing variant.
- **Titles and descriptions:** patterns in the `seo` catalogue. `Seo\TitleChain::fit(patterns, name)` takes `list<[key, params]>`, most specific first, returns the first ≤ 60 and as a last resort cuts the name at a word, keeping the suffix. `{site}` is supplied. Venue chain: `{name} in {town} mieten`, `{name} in {town}`, `{name}`; canton only: `{name} im Kanton {canton}`, `{name}`; region text never reaches a title. `Seo\MetaDescription::fit()`: whitespace collapsed, ≤ 155, ends at a sentence (at least 70 characters) else at a word with `…`.
- **Venue facts:** `Seo\ValueObject\VenueFacts` has every place fact nullable (`street`, `zip`, `town`, `canton`, `lat`, `lng`) and `fromDetail()` is the one adapter from `VenueDetail`. `og:type` is `place` only with coordinates.
- **JSON-LD:** `site_jsonld()` (default of `base.html.twig`'s `jsonld` block; children call `parent()`), `venue_jsonld(venue)`, `breadcrumb_jsonld(items)` (`list<{name, url?}>`, last item without `url`), `listing_jsonld(page)` (`ItemList`; call it only when `not seo.noindex`). `Seo\JsonLdScript::render()` escapes so a value cannot close the block. `VenueJsonLd`: `["EventVenue","LocalBusiness"]` with `address` only with ZIP and town; otherwise `EventVenue`, with `containedInPlace` `AdministrativeArea` when the canton is known. Nulls and empties are dropped, so no empty key or placeholder is ever emitted. `aggregateRating` (`bestRating: 10`) only in the `LocalBusiness` shape. **Never** `telephone` or `email`: JSON-LD is page HTML and DP-11 keeps the number out of it.
- **Sitemap and robots:** `sitemap` `/sitemap.xml` is always an index, `sitemap_pages` `/sitemap-pages.xml` lists `Seo\PagesSitemap::urls()` (the one class #17 and #18 extend), `sitemap_venues` `/sitemap-venues-{n}.xml` holds 1,000 URLs each and answers 404 past the last file (`Seo\Controller\SitemapController`, `templates/seo/sitemap_{index,urlset}.xml.twig`). Only `loc`, `lastmod`, `image:image`/`image:loc`. `robots` `/robots.txt` (`Seo\Controller\RobotsController`; body built in PHP because the raw-text scanner flags it in a template). All unlocalized, `public, s-maxage=600, stale-while-revalidate=86400`; #19 must purge them. Scheme and host come from the request, so production needs #20's `trusted_proxies`. Local copies of the schemas: `tests/Fixtures/Seo/` (`sitemap-with-images.xsd` wraps the two, because `sitemap.xsd` alone rejects `image:image`).
- **Error pages:** `templates/seo/_error_page.html.twig` and `templates/bundles/TwigBundle/Exception/{error404, error410, error}.html.twig`, styles `assets/styles/seo/_error-page.scss`, strings `error.*`. They override `hreflang` and `jsonld` with nothing and run no query, so the 500 page survives a database outage: `seo/_footer_cantons.html.twig` (#17) must stay query-free or `ErrorPagesQueryFreeTest` fails. `/admin` errors use the same public pages. The custom templates render only with debug off; preview them at `/_error/404`.

**Built in #17 (`seo-landing-pages`).** No migration.

- **Meta:** `listing_seo(criteria, page, landing = null)` → `ListingMeta::for(…, ?LandingPage)`. The canonical is the landing path of the criteria (`?page=N` kept only unfiltered); `noindex, follow` when a parameter besides `page` remains or the facet came from the query (`/hochzeitslocations?canton=zh` canonicalises to `/hochzeitslocations/zuerich`). Landing titles run through `TitleChain` (`landing.<type>.title_count`, `title`, `title_short`, `title_name`, numbered `title_page*`; building `title_count`, `title`, `title_page`), the h1 as last resort; descriptions through `MetaDescription::fit()`. Landing pages add `breadcrumb_jsonld()`.
- **Sitemap:** `PagesSitemap::urls()` appends every live landing page after the listing, absolute, page 1, with `lastmod` from `VenueSitemapQuery::newestChangeByGroup()` (`Venues\ReadModel\SitemapChanges`: newest change per canton code, town id and building code, one statement on the class's own `PUBLISHED`/`LASTMOD`).
- **Footer:** `seo/_footer_cantons.html.twig` fills #2's slot through `footer_landing_cantons()` (the `LandingFooter` item, sorted by canton name); a cold pool renders nothing, and it never queries (`ErrorPagesQueryFreeTest` covers warm and cold). #19 purges the landing paths with the listing.

**Built in #26 (`cms-pages`).** No migration.

- **Override rule (P5):** `ListingMeta` (constructor gains `ContentBlocks`) takes a staff `landing.<prefix>.title` (trimmed, used as stored; ≤ 60 is the write side's rule) and `.description` (through `MetaDescription::fit()`) for the landing page it computes the title for, on every page that is not numbered, filtered or not (so `/hochzeitslocations?canton=zh` carries Zürich's); page ≥ 2 keeps #17's `title_page*` chain and description. Without a row the meta is #17's byte for byte.
- **`ListingMeta::defaults(LandingPage): array{title: string, description: string}`:** #17's page-1 title and description without staff text, for the editor's placeholders and search-result preview.
- **`Seo\Extension\LandingPageCatalogue`** (implements `Shared\Extension\LandingPageSource`, `ResetInterface`): every `LandingPages::candidates()` entry as a `LandingPageEntry` (the h1 as name, #17's intro with its count, `defaults()`, `passesThreshold` from `%landing.min_venues%`, the absolute URL only above it), ordered canton, town, building, then by `\Collator` name; memoized per request. Seo owns it because the defaults are `ListingMeta`'s and Search may not use Seo (P3).
- **Tests:** `tests/Integration/Seo/LandingPageCatalogueTest.php`, `tests/Application/Seo/LandingOverrideSeoTest.php`, `LandingTitleTest` (builds `ListingMeta` with an empty warm `ContentBlocks`), `ErrorPagesQueryFreeTest` (footer note warm and cold; the pushed request's locale is `de`).

**Built in #19.** Sitemaps (the past-the-last-file 404 too) and `robots.txt` stay a day, `stale-if-error` a day, and are invalidated with every purge.
