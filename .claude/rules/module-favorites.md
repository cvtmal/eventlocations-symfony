---
paths:
  - "src/Favorites/**"
  - "templates/favorites/**"
  - "assets/styles/favorites/**"
  - "assets/js/merkliste.js"
  - "translations/favorites*"
  - "admin-ui/tests/public/merkliste.test.js"
  - "tests/{Unit,Integration,Application}/Favorites/**"
---
Append current-state lines for your cycle at the end (last commit).

### Favorites

Holds the Merkliste cookie codec, the toggle and sync endpoints, the `/merkliste` page and `merkliste.js`; strings in `favorites`. Read it before changing the cookie format or any `[data-merken]` markup. The list lives in the browser: no table, command or handler (a handler cannot set a response cookie); Favorites may use Search and Venues, nothing imports it.

## Cookie and codec

- **Cookie `merkliste`:** host-only, `Path=/`, `Max-Age` 34560000 (400 days) renewed on every write, `SameSite=Lax`, `Secure` on HTTPS, not `HttpOnly`; venue numbers in saved order, wire form `12%2C34`; empty list deletes it. A trailing comma marks a JS-written list not yet re-issued: only JS writes it, readers ignore it, `POST`/`GET /merkliste` drop it.
- **`Favorites\ValueObject\MerklisteCookie`** (`MAX` 100): token `^[1-9]\d{0,9}$`, <= 2147483647; garbage dropped, duplicates keep their first position, first 100 win, array-shaped cookie = absent. At 100 an add is refused, nothing evicted; the codec owns that rule (no handler, unlike `validation.md`'s example). `merkliste.js` repeats rule and format: change both (`MerklisteScriptTest` pins the constants).

## Endpoints

No session; toggle and sync touch no database. Answers are `private, no-store` with `X-Robots-Tag: noindex, nofollow`. Public pages never read the cookie (no `Set-Cookie`, no `Vary: Cookie`; `MerklisteCookieDoesNotVaryTest`).

- **Toggle** `merkliste_toggle` `POST /merkliste/{number}` (`MerklisteToggleController`, no-JS path): same-origin CSRF check first, required (`isCsrfTokenValid('submit')`, `SameOriginCsrfTokenManager` via `config/packages/csrf.yaml`; `_token` = constant `csrf-token`; `SameSite=Lax` alone lets the answer's `Set-Cookie` replace the list): 403 without cookie. Then 303 to a same-origin `Referer`, else `/merkliste` (also a refused add at 100); number above INTEGER: 404. `intent=remove` (exact string, from `_remove_button`) only removes: idempotent, no `Set-Cookie` if absent, never refused at 100; without it a true toggle (a cached Merken button cannot know the state).
- **Sync** `merkliste_sync` `POST /merkliste` (`MerklisteSyncController`): re-issues a JS-written list because WebKit caps `document.cookie` at 7 days (MK-2). No number, so no flip; it echoes the request's list: empty writes nothing and never deletes, clean writes nothing, else re-issued normalised. Same CSRF check. 204 without `Location` (`HttpCache` invalidates a POST's `Location`: the cached page).
- **Page** `merkliste` `GET /merkliste` (`MerklisteController`): the only GET reading the cookie, `<meta>` noindex too; cards via `ListingQuery::cardsByNumbers()` and `search/_card.html.twig` with `action: 'remove'`; prunes garbage, duplicates, unpublished or deleted venues (`Set-Cookie` when changed).

## merkliste.js

- Loaded on every page by `base.html.twig`; reasons are in the script's comments, behaviour in `admin-ui/tests/public/merkliste.test.js`. It refreshes on bfcache restore, tab visible and `window` `focus` (side-by-side windows miss `visibilitychange`); on `/merkliste` (`[data-merkliste-page]`) it reloads when the cookie differs from the cards, never at load (no loop). A removal drops the card in place, focus goes to the next remove button, else the previous, else the h1; `REMOVE_GUARD_MS` 450 ms from the last performed removal swallows a second one (no undo), `event.repeat` a held Enter.
- **Sync:** 3 s after the last write of a burst (`fetch`, 2 s timeout), 3 s after a load or bfcache restore that finds the marker, `keepalive` at once on hidden/`pagehide`; no marker, no request; failure silent (next view retries); an answer that leaves the marker is not retried until this tab writes again. A per-tab journal re-asserts additions at every sync and landing, removals only at its own landing and only if newer than that flight. `MerklisteScriptTest` pins `SYNC_TIMEOUT_MS + 1000 <= SYNC_DELAY_MS`.
- **Markup:** header heart `[data-merkliste-link]` (the only one; `href` = sync URL): `data-sync-token`, `data-toast-added|removed|full|link` (`__name__`), no `aria-label` (name = `.visually-hidden` word + badge `[data-merkliste-count]`; axe `label-content-name-mismatch`). Buttons `data-merken` / `data-merkliste-remove` + `data-merken-name`. Page hooks `[data-merkliste-total|full|empty|item|title]` are always rendered, `hidden` when unused. Toast markup is built twice (`components/_toast.html.twig`, the script): change both; one toast at a time, 6 s, kept while hovered/focused. After an add: `CustomEvent('merkliste:add')` on `document` for `stats.js`.

## Templates, limits, tests

- `favorites/_merken_button.html.twig` (`variant` card|detail): `aria-label` only on `card`; on `detail` the visible word is the name, so hide it visually, never `display: none`. `merkliste.html.twig` sets `stats_page` `merkliste`. `_merken.scss`: the heart fills on `[aria-pressed='true']` (not colour alone), `.merken--card` in `.card__top` (word at every width, never a disc on the photo). `_merkliste.scss`: write `.listing.merkliste` (two classes beat `.listing.container`).
- **Limits:** no saved state on cached pages without JS (`/merkliste` is the no-JS source of truth); the `[data-merkliste-total]` count line carries its own plural forms (`data-count-one|other`, `__count__`); Safari/iOS 400 days rest on the re-issue, untested in WebKit (else 7 days, 24 h via link-decorated tracker URLs); a removal during another tab's sync can return.
- **Tests:** seed the BrowserKit jar with domain `localhost` (`new Cookie('merkliste', $value, null, '/', 'localhost')`) or a response cannot replace or delete it; POSTs send `_token=csrf-token` and `HTTP_SEC_FETCH_SITE: same-origin`.
