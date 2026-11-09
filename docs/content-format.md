# Venue content format

Real venues reach the site through content folders (S8): one folder per venue with a
`venue.yaml` and its photos and PDFs. `app:venues:import` validates the folders and writes
them into the database. Staff can also edit a venue in the back office (CMS, #24); the
import then keeps that venue as it is until told otherwise (see
[After a CMS edit](#after-a-cms-edit)). This page is the reference for
writing folders by hand.

The machine-readable definition is the JSON Schema
[`content-format.schema.json`](content-format.schema.json) (draft-07). The import validates
every `venue.yaml` against it, so this page and the schema cannot disagree for long: a test
validates the example below against the schema.

## Where the folders live

The content root is `CONTENT_ROOT` (`.env`, default `<project>/content`). It is git-ignored:
**venue content is never committed**. The import reads `<CONTENT_ROOT>/venues/`; a different
root can be passed as the command's argument.

```
<CONTENT_ROOT>/venues/<key>/
  venue.yaml                     the venue (required)
  photos/<file>                  gallery photos, listed under photos:
  rooms/<room-key>/photos/<file> room photos, listed under that room's photos:
  documents/<file>.pdf           PDFs, listed under documents:
```

### The key

The folder name is the venue's **key**: lower-case letters and digits in words joined by
single hyphens (`schloss-beispiel`, at most 100 characters). The key identifies the venue
across imports and **never changes**. It is not the URL: the URL slug comes from the name.

- Renaming the venue: change `name:` and keep the folder. The old URL redirects to the new one.
- Renaming the folder makes a new venue; the old one disappears only with `--prune`.

Room keys (`rooms[].key`) follow the same rules, are unique within the venue and never
change either: they keep a room's photos attached to it when rooms are reordered.

## Tier

A folder carries no tier. The import creates a venue on the Free tier and never changes the tier of an existing one; a paid tier comes only with a paid term that staff record in the CRM («Zahlung erfassen», G9).

## Example

Every field, with fictional values. Only `name`, `capacity.max` and
`translations.de.summary` are required. A venue may have no address at all. A draft
(`status: unpublished`) may leave out `translations` altogether (F14): the import then gives
it an empty summary and the slug from the name, and the text is written later.

```yaml
# yaml-language-server: $schema=../../../docs/content-format.schema.json
name: Schloss Beispiel                # ≤ 150 characters, not translated; the URL slug comes from it
status: published                     # optional: published (default) or unpublished
price_level: medium                   # optional: moderate | medium | exclusive
capacity:
  min: 20                             # optional, 1–50000, at most max
  max: 180                            # required, 1–50000
  seated: 120                         # optional, 1–50000, seats at a seated event
  standing: 180                       # optional, 1–50000, guests at a standing reception
address:                              # optional
  street: Seestrasse 1                # optional, ≤ 150, needs zip and town
  zip: '8001'                         # optional, four digits, quoted; zip and town come together
  town: Zürich                        # optional, official town name (Ortschaft)
  canton: zh                          # optional; the venue's canton, alone or to tell two towns apart
geo:                                  # optional, both or neither
  lat: 47.3723                        # 45.8–47.9
  lng: 8.5423                         # 5.9–10.6
distances:                            # each optional; km 0–500 with at most one decimal
  station: {km: 0.9, name: Bahnhof Beispiel}
  highway: {km: 3.2, name: A1}
  airport: {km: 12.0, name: Flughafen Zürich}
contact:                              # all optional
  phone: '+41441234567'               # E.164, quoted
  inquiry_email: anfragen@example.ch  # receives the inquiries
  website: https://example.ch         # starts with http:// or https://
  contact_person: Anna Muster
  instagram: https://www.instagram.com/schloss.beispiel   # starts with https://
faq:                                  # optional; null or missing = unknown
  daylight: true
terms:                                # lists of codes per group (see the table below)
  setting: [lake, countryside]
  building: [castle, event_venue]
  suitable_for: [corporate_event, seminar, birthday]
  infrastructure: [air_conditioning, kitchen, cloakroom]
  furniture: [chairs, tables, standing_tables]
  technology: [projector_screen, wifi, sound_system]
  surroundings: [garden, terrace]
  amenity: [overnight, parking]
  catering: [in_house, buffet, vegetarian]
  language: [de, en, fr]
main_terms:                           # optional; each code must be listed under terms
  setting: lake
  building: castle
  suitable_for: corporate_event
translations:
  de:                                 # required
    summary: Fiktives Schloss am See für Firmenanlässe und Feiern bis 180 Gäste.   # required, ≤ 200
    description: |                    # optional
      Ein erfundenes Schloss mit Park und Blick auf den See.
      Zwei Säle, ein Gewölbekeller und eine Terrasse.
    meta_description: Fiktives Schloss Beispiel am See, Anlässe bis 180 Gäste.   # optional, ≤ 160
    parking_text: 40 Parkplätze direkt beim Schloss.   # optional, ≤ 250
    ambience: Festlich mit Seesicht     # optional, ≤ 60, verbatim
    capacity_note: 120 Sitzplätze im Rittersaal, 60 im Keller.   # optional, ≤ 250
    opening_hours: Mai bis Oktober, täglich ab 10 Uhr.   # optional, ≤ 250
    region: Seeland                   # optional, ≤ 60, verbatim; where the venue is when it has no canton
photos:                               # gallery, in display order; files in photos/
  - file: aussen.jpg
    alt: {de: Schloss Beispiel vom See aus}   # optional, ≤ 700
  - file: park.webp
rooms:                                # in display order
  - key: rittersaal
    translations:
      de: {name: Rittersaal, description: Der grosse Saal mit Kronleuchtern.}   # name ≤ 100
    capacity: {min: 20, max: 120}     # optional; ignored once a seating entry states a max
    area_m2: 180                      # optional, 1–50000
    height_m: 6.5                     # optional, > 0 and ≤ 30, at most two decimals
    floor_material: parquet           # optional: various | parquet | carpet | stone | wood
    seating:                          # optional: seminar | banquet | apero | concert | u_shape | block
      banquet: {min: 20, max: 100}
      apero: {max: 150}
    photos:                           # files in rooms/rittersaal/photos/
      - file: saal.jpg
        alt: {de: Gedeckte Tafel im Rittersaal}
  - key: gewoelbekeller
    translations:
      de: {name: Gewölbekeller}
    area_m2: 60
    seating:
      apero: {max: 60}
documents:                            # in display order; files in documents/
  - file: preisliste.pdf
    title: {de: Preisliste}           # required, ≤ 200
```

## Field reference

| Field | Type and limits | Notes |
|---|---|---|
| `name` | text ≤ 150, required | Proper noun, not translated. Changing it changes the URL slug; the old slug redirects. |
| `status` | code | `published` (default) or `unpublished` (hidden, kept). `published` is a wish for an entry nobody manages: see [the draft rule](#the-draft-rule). |
| `price_level` | code | `moderate`, `medium`, `exclusive`. |
| `capacity.min`, `capacity.max` | 1–50000 | `max` required; `min` ≤ `max`. |
| `capacity.seated`, `capacity.standing` | 1–50000 | The venue's own numbers; not compared with `max`. |
| `address.street` | text ≤ 150 | Needs `zip` and `town`. |
| `address.zip` | `'NNNN'` | Quote it. Any ZIP of the town works, not only the lowest. `zip` and `town` come together. |
| `address.town` | text | Official town name; matched exactly after folding case and umlauts (`Zürich` = `ZÜRICH` = `Zuerich`, but not `Zurich`). |
| `address.canton` | one of the 27 codes (the 26 cantons `zh`, `be`, … and `li` for Liechtenstein) | The venue's canton when there is no town, or to tell two towns apart (the import then says so). With a town it must match the town's canton; a mismatch fails the venue as an unknown town. |
| `geo.lat`, `geo.lng` | decimal degrees | Inside Switzerland or Liechtenstein. Optional, both or neither. |
| `distances.<station\|highway\|airport>` | `km` 0–500 (one decimal), `name` ≤ 150 | A name needs its km. |
| `contact.phone` | E.164 (`'+41…'`) | Quote it. |
| `contact.inquiry_email` | e-mail ≤ 254 | |
| `contact.website` | URL ≤ 500 | `http://` or `https://`. |
| `contact.contact_person` | text ≤ 150 | |
| `contact.instagram` | URL ≤ 500 | `https://` only. |
| `faq.daylight` | true, false, null | |
| `terms.<group>` | list of codes | See the vocabulary below. |
| `main_terms.<setting\|building\|suitable_for>` | one code | Must also be listed under `terms`. |
| `translations.<locale>` | `summary` ≤ 200 (required once `translations` is given), `description`, `meta_description` ≤ 160, `parking_text` ≤ 250, `ambience` ≤ 60, `capacity_note`, `opening_hours` ≤ 250, `region` ≤ 60 | `de` is required, except in a draft (`status: unpublished`), which may have no `translations` at all; other locales only once the site serves them (today only `de`). |
| `photos[]` | `file`, `alt.<locale>` ≤ 700 | Order = display order. |
| `rooms[]` | `key`, `translations.<locale>.name` ≤ 100 (+ `description`), `capacity`, `area_m2` 1–50000, `height_m` (> 0, ≤ 30, two decimals), `floor_material`, `seating`, `photos` | Order = display order. A seating entry needs `min` or `max`. Seating codes and labels: `seminar` Seminar/Parlament, `banquet` Bankett, `apero` Apéro/Stehempfang, `concert` Konzert/Reihen, `u_shape` U-Form, `block` Block. Once an entry states a `max`, the room's guest range is the seating's: the greatest `max` and the least `min` of the entries with a `max` (`capacity` is then ignored). |
| `documents[]` | `file` (`.pdf`), `title.<locale>` ≤ 200 | `title.de` is required. |

The event facts (`price_level`, `capacity.seated`, `capacity.standing`,
`contact.instagram`, the term groups `amenity`, `catering` and `language`, and the three
texts `ambience`, `capacity_note`, `opening_hours`) are all optional. A missing one is
unknown and the detail page shows nothing for it. Nothing is inferred: set a fact only when the
venue stated it. The import applies a folder as a whole, so a field omitted on an update
resets to unknown.

### Vocabulary

| Group | Codes |
|---|---|
| `setting` | `river`, `lake`, `countryside`, `mountains`, `city` |
| `building` | `bar_club`, `farm_barn`, `mountain_hut`, `brewery_winery`, `medieval_castle`, `cafe`, `casino`, `digital_venue`, `experience_creative`, `event_hall`, `event_venue`, `factory_hall`, `mobile_venue`, `leisure_facility`, `gallery_studio`, `garden_greenhouse`, `hangar_shipyard`, `historic_site`, `cave_fortress`, `hotel`, `cinema`, `congress_centre`, `cultural_centre`, `exhibition_hall`, `museum`, `open_air`, `party_room`, `restaurant`, `ship`, `castle`, `seminar_room`, `meeting_room`, `theatre`, `tram_train_bus`, `villa`, `forest_hut`, `tent`, `guild_hall` |
| `suitable_for` | `corporate_event`, `seminar`, `conference`, `workshop`, `meeting`, `product_presentation`, `general_assembly`, `team_event`, `christmas_party`, `club_event`, `apero`, `birthday`, `family_celebration`, `wedding`, `party`, `concert`, `exhibition` |
| `infrastructure` | `air_conditioning`, `shower`, `cloakroom`, `heating_ventilation`, `kitchen`, `backstage_rooms`, `lectern` |
| `furniture` | `chairs`, `tables`, `standing_tables` |
| `technology` | `projector_screen`, `wifi`, `sound_system`, `video_conferencing`, `stage_lighting`, `flipchart_moderation`, `tv_screens`, `interpreting_system` |
| `surroundings` | `garden`, `spa_wellness`, `terrace`, `playground`, `barbecue`, `golf` |
| `amenity` | `overnight`, `panorama`, `lake_view`, `dance_floor`, `kids_room`, `outdoor_dining`, `parking`, `wheelchair_access`, `public_transport`, `tent` |
| `catering` | `in_house`, `external_allowed`, `bar_drinks`, `byo_drinks`, `buffet`, `finger_food`, `coffee_break`, `grill`, `vegetarian`, `vegan`, `gluten_free`, `allergy_friendly` |
| `language` | `de`, `en`, `fr`, `it`, `es`, `pt`, `nl`, `cs`, `ru`, `hu`, `gsw`, `ro` |

An unknown code fails the venue with its position, e.g. `/terms/building/0: "palace" is not an allowed value`.

### YAML pitfalls

- Quote the ZIP (`'8001'`) and the phone (`'+41441234567'`).
  Unquoted, YAML reads numbers (and full dates as timestamps) and the schema rejects them.
- Unknown fields are errors, at every level (a typo like `sumary:` is reported, not ignored).
- Decimals use a dot (`6.5`), never a comma.

## Files

- Photos: `.jpg`, `.jpeg`, `.png`, `.webp` (any case). The import checks the bytes, not only
  the extension; GIF, AVIF and other formats fail.
- Documents: PDF only (the file must start with `%PDF-`).
- Every file in `photos/`, `rooms/<key>/photos/` and `documents/` must be listed in
  `venue.yaml`, and every listed file must exist. Files starting with a dot (`.DS_Store`)
  are ignored. A `rooms/<key>/` folder needs a room with that key.
- The same image twice in one list fails; the same image in the gallery and in a room is fine.
- Symbolic links are not allowed (for the venue folder, `venue.yaml`, the sub-folders and
  every listed file).
- A photo is identified by its bytes, not its name: renaming or reordering files keeps the
  photo (its id, its generated sizes and its URLs). Changed bytes make a new photo; the old
  one's files are deleted.

## What the import does

```
make sf c="app:venues:import [path] [--dry-run] [--only=key] [--prune] [--force] [--overwrite-cms-edits]"
```

1. Reads every folder under `venues/` (in name order), validates it against the schema and
   the rules above. A folder with problems fails **alone**, with every problem listed; the
   others are imported. One folder is one transaction.
2. A venue edited or deleted in the CMS since its last import is kept as it is and listed
   as `cms_edited` (see [After a CMS edit](#after-a-cms-edit)). A folder whose content
   (fields and file bytes) is unchanged since the last import is skipped: running the
   import twice changes nothing.
3. Creates or updates the venue by key. The venue number (used in saved favourites) never
   changes. Rooms keep their identity by key, photos and documents by their bytes.
4. Slugs: the name's slug; when another venue has it (also as an old slug), the town, if
   there is one, is appended (`schloss-beispiel-zuerich`), then a counter (`-2`, `-3`). Old
   slugs are kept so their URLs redirect; renaming back takes the old slug back.
5. When a folder names a town, it is resolved by ZIP and name; no match or several fail the
   venue.
6. Originals are copied to `MEDIA_ROOT/content/<key>/<sha256>.<ext>`; the web sizes are
   generated only for new photos. Files of removed photos and documents are deleted after
   the database commit.
7. Each run (except a dry run) is recorded in the `content_imports` table with its counts
   and errors. The pages of every created, updated or unpublished venue are purged from the
   HTTP cache after its commit, and the listings once per run that changed something.

| Option | Effect |
|---|---|
| `path` | Content root that contains `venues/` (default `CONTENT_ROOT`). |
| `--dry-run` | Validates and reports what would be created, updated, unchanged, failed, kept because of a CMS edit (and unpublished with `--prune`); writes nothing, records nothing. |
| `--only=<key>` | Imports one folder. |
| `--prune` | Unpublishes content venues whose folder is gone (files kept; the folder coming back publishes an unedited venue again). A folder that exists but is invalid is not pruned. A venue edited in the CMS is kept published and listed, unless `--overwrite-cms-edits` is given too. Not combinable with `--only`. |
| `--force` | Applies every folder even when unchanged (photos are still not regenerated). It never overwrites a venue edited in the CMS. |
| `--overwrite-cms-edits` | Also applies the folders of venues edited in the CMS since their last import, even when the folder is unchanged, and recreates venues deleted in the CMS (with a new number). **Every CMS edit of those venues is lost**, including photos, rooms and documents added by hand. With `--prune` it also unpublishes edited venues whose folder is gone. |
| `-v` | Also lists the unchanged venues. |

The exit code is `1` when any folder failed (the message lists `<key>: <problem>` for each),
otherwise `0`. Venues kept because of a CMS edit are listed after the summary and do not
change the exit code. To hide a venue by hand, set `status: unpublished`.

After the summary the run lists, in a dry run too and without changing the exit code:

- **Held as draft**: `<key>: facts, description`, the folders [the draft rule](#the-draft-rule)
  keeps offline and what each lacks.
- **Notes**: `<key>: event_types: N > M (Free)` when a folder names more event types than the
  Free tier shows (D43). Every type is imported; staff cut the list to the plan while they
  check the facts.

### The draft rule

An entry nobody answers for, that is one without an active `/konto` manager, goes online
only when staff ticked «Fakten geprüft» in the CMS and its default-locale `description` is
written (F14, D54). The import applies it to a folder that asks `published`: a new venue is
never checked, so **every imported venue lands as a draft**; an existing one moves online on
the first import after both are done (`--force --only=<key>` when the folder is unchanged).
The rule gates only the move online: an entry already online stays online. The CMS publish
button and the moderation `keep` refuse the same entry with «Fakten nicht geprüft» and
«Beschreibung fehlt». An entry with a manager keeps G12's rule: online even unchecked, but
`noindex` until both are done. A verified claim and a staff approval in the review queue tick
«Fakten geprüft» themselves (D45).

Run one import at a time; there is no lock between two imports. A CMS save and the import
of the same venue do not interleave: the import locks the venue's row before it checks the
guard.

### After a CMS edit

Every save in the CMS marks the venue (`venues.cms_edited_at`). While the mark is set, the
import does not apply that venue's folder, not with `--force` and not with `--prune`; it
lists the venue as `cms_edited` instead. The guard is per venue, not per field: a folder
change to a field the CMS never touched is not applied either.

- To take the folder's content again, run the import with `--overwrite-cms-edits` (with
  `--only=<key>` for one venue). The folder then replaces the venue as a whole and the mark
  is cleared; a later import applies the folder as usual.
- To keep the CMS version for good, leave it: the folder is skipped on every run. Changes
  to it belong in the CMS from then on, or in the folder followed by an overwrite.
- A venue deleted in the CMS keeps its key in `venue_deleted_content_keys`, so its folder
  does not bring it back. `--overwrite-cms-edits` recreates it with a new number, and old
  Merkliste entries of it do not return.

The import (and `app:media:regenerate`) raises PHP's `memory_limit` to `512M` for the run,
never lowering a higher one, and warns when it cannot: rendering the web sizes of a photo
above about 40 MP needs more than the stock 256M (a 60 MP photo peaks at about 310 MiB).
Run a full import with `--no-debug` in dev: with debug on, Doctrine keeps every query and
its backtrace for the whole command (about 2.5 KB each), so a few hundred venues come close
to the 512M.

## Venue sheet

The owner keeps venues in a spreadsheet; `app:venues:convert-sheet` turns each row into a
draft folder (`status: unpublished`) that the import above then reads. The template is
`tests/Fixtures/Onboarding/venue-sheet.csv` (fictional rows).

```
make sf c="app:venues:convert-sheet <csv> [path] [--dry-run]"
```

- Comma or semicolon separated (taken from the header line), UTF-8 with or without BOM; the
  columns are found by name, in any order. A missing or unknown column refuses the file.
- Per row it prints `created` (folder written), `exists` (a folder with that key is there;
  it is never overwritten) or `failed` with `row N: <problem>`; the exit code is `1` when a
  row failed, the others are still written. `--dry-run` writes nothing. No database row is
  written: run `app:venues:import --dry-run`, then the import.
- Rows are checked by the converter (below) and then against the schema, as a folder would be.

| Column | Becomes | Rule |
|---|---|---|
| `key` | folder name | Optional: empty derives `<name>-<town>` (ASCII, lower case, cut at a hyphen to 100), printed by the run. |
| `source` | — | `owner`, `hochzeitslocation` (a customer who answered yes, D29) or `eventlokale` (the crawl's draft sheet). |
| `name`, `street`, `zip`, `town`, `canton`, `lat`, `lng` | `name`, `address.*`, `geo.*` | As in the field reference. |
| `region` | `translations.de.region` | Needs `summary`. |
| `capacity_min`, `capacity_max`, `capacity_seated`, `capacity_standing` | `capacity.*` | `capacity_max` required. |
| `price_level` | `price_level` | Code. |
| `event_types` | `terms.suitable_for` | Our codes, separated by `\|`. More than Free shows is noted, not cut. |
| `building` | `main_terms.building` + `terms.building` | Optional code. |
| `phone`, `inquiry_email`, `website`, `contact_person`, `instagram` | same names | A Swiss phone number is written as `+41…`. |
| `summary`, `description` | `translations.de.*` | Optional; without a `summary` the folder has no texts (a draft may). A `description` needs a `summary`. |
| `consent_on`, `consent_from` | — (CRM) | Required for `source = hochzeitslocation` and refused for the others: the date of the yes (`YYYY-MM-DD`, not in the future) and the address it came from. `app:crm:record-consents` puts them on the Location's timeline. |

Every folder the sheet makes is a draft; it goes online by [the draft rule](#the-draft-rule).

## Editor support

Point the YAML language server (VS Code "YAML" extension, PhpStorm) at the schema with a
first line comment; the path is relative to the `venue.yaml`:

```
# yaml-language-server: $schema=<path to the checkout>/docs/content-format.schema.json
```

The editor then completes codes (`status`, `terms.building`, …) and shows each field's limits.

## What is not in the format

The format holds every venue column of the site and nothing else. Barrier-free access and
free parking are `amenity` codes (`wheelchair_access`, `parking`), not fields of their own.

`rating` and `reviews` were removed in #53 (the site shows the Google rating instead, set in
the CMS); a `venue.yaml` that still has them is refused.

The wedding-only facts were removed in event-vocabulary (F3, format version 2): the `party`
section (`allowed`, `has_curfew`, `curfew_at`), `translations.<locale>.party_note`, and the
term groups `ceremony`, `dining`, `vendor` and `usage`. `catering` is a new term group, and
the codes of `suitable_for`, `infrastructure`, `furniture`, `technology` and `amenity` were
re-curated for event venues (`wedding_venue` is no `building` code any more; `wedding` stays
a `suitable_for` code). A `venue.yaml` of version 1 that still names one of them is refused
with the position of the field, e.g. `/: Unknown properties: party`.

`faq.own_catering` was removed in inquiry-event (F9, D33): who caters is the `catering` terms
`in_house` and `external_allowed`, the one source the detail page and the inquiry form read. A
`venue.yaml` that still has it is refused by the schema.
