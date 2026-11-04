# Venue content format

Real venues reach the site through content folders (S8): one folder per venue with a
`venue.yaml` and its photos and PDFs. `app:venues:import` validates the folders and writes
them into the database. Staff can also edit a venue in the back office (CMS, #24); the
import then keeps that venue as it is until told otherwise (see
[After a CMS edit](#after-a-cms-edit)). This page is the reference for
writing folders by hand and for the CSV export (#21, #32), which generates them.

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
- Generated folders use `<ascii-name-slug>-<first 8 of the Wix id>`, e.g.
  `schloss-beispiel-1a2b3c4d`. The name keeps it readable; the id keeps it unique. A later
  export finds the folder by its last 8 characters, so a changed name does not make a new
  folder.

Room keys (`rooms[].key`) follow the same rules, are unique within the venue and never
change either: they keep a room's photos attached to it when rooms are reordered.

## Example

Every field, with fictional values. Only `name`, `tier`, `capacity.max` and
`translations.de.summary` are required. A venue may have no address at all.

```yaml
# yaml-language-server: $schema=../../../docs/content-format.schema.json
name: Schloss Beispiel                # ≤ 150 characters, not translated; the URL slug comes from it
tier: premium                         # beliebte_location | empfehlung | top_premium | premium | basic
status: published                     # optional: published (default) or unpublished
price_level: medium                   # optional: moderate | medium | exclusive
capacity:
  min: 20                             # optional, 1–50000, at most max
  max: 180                            # required, 1–50000
  seated: 120                         # optional, 1–50000, seats at a seated dinner
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
  own_catering: allowed               # allowed | fixed_caterer
  daylight: true
party:                                # optional; missing = unknown; its fields may be null
  allowed: limited                    # 'yes' | limited | 'no', quote yes and no
  has_curfew: true                    # false = no curfew
  curfew_at: '02:00'                  # HH:MM 00:00–23:59, quoted; needs has_curfew: true
rating:                               # optional; score and count together
  score: 9.0                          # 0–10, at most one decimal
  count: 47
  highlights: [food, atmosphere, architecture]   # at most 3, no repeats
terms:                                # lists of codes per group (see the table below)
  setting: [lake, countryside]
  building: [castle, event_venue]
  suitable_for: [wedding, birthday, corporate_event]
  infrastructure: [wifi, kitchen, cloakroom]
  furniture: [chairs, tables]
  technology: [sound_system, projector_screen]
  surroundings: [garden, terrace]
  ceremony: [free_ceremony, rain_alternative]
  amenity: [overnight, dance_floor]
  dining: [vegetarian, vegan]
  vendor: [flowers_decor, dj]
  usage: [exclusive]
  language: [de, en, fr]
main_terms:                           # optional; each code must be listed under terms
  setting: lake
  building: castle
  suitable_for: wedding
translations:
  de:                                 # required
    summary: Fiktives Schloss am See für Hochzeiten bis 180 Gäste.   # required, ≤ 200
    description: |                    # optional
      Ein erfundenes Schloss mit Park und Blick auf den See.
      Zwei Säle, ein Gewölbekeller und eine Terrasse.
    meta_description: Fiktives Schloss Beispiel am See, Hochzeiten bis 180 Gäste.   # optional, ≤ 160
    parking_text: 40 Parkplätze direkt beim Schloss.   # optional, ≤ 250
    ambience: Romantisch mit Seesicht   # optional, ≤ 60, verbatim
    capacity_note: 120 Sitzplätze im Rittersaal, 60 im Keller.   # optional, ≤ 250
    party_note: Musik bis 2 Uhr, danach Ausklang im Keller.   # optional, ≤ 250
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
    capacity: {min: 20, max: 120}     # optional
    area_m2: 180                      # optional, 1–50000
    height_m: 6.5                     # optional, > 0 and ≤ 30, at most two decimals
    floor_material: parquet           # optional: various | parquet | carpet | stone | wood
    seating:                          # optional: seminar | banquet | apero | concert
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
reviews:                              # replaced as a whole on every change
  - first_name: Laura                 # ≤ 50
    role: visitor_f                   # visitor_f | visitor_m | organiser_f | organiser_m
    month: '2025-06'                  # YYYY-MM, quoted
    locale: de                        # optional, language of the text, default de
    text: Ein wunderschöner Tag, alles hat gepasst.   # ≤ 1000
```

## Field reference

| Field | Type and limits | Notes |
|---|---|---|
| `name` | text ≤ 150, required | Proper noun, not translated. Changing it changes the URL slug; the old slug redirects. |
| `tier` | code, required | `beliebte_location`, `empfehlung`, `top_premium`, `premium`, `basic` (S6: sets the listing order). |
| `status` | code | `published` (default) or `unpublished` (hidden, kept). |
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
| `faq.own_catering` | `allowed`, `fixed_caterer`, null | |
| `faq.daylight` | true, false, null | |
| `party.allowed` | `'yes'`, `limited`, `'no'`, null | Quote yes and no. |
| `party.has_curfew` | true, false, null | `false` means no curfew, null unknown. |
| `party.curfew_at` | `'HH:MM'` `00:00`–`23:59`, null | Quote it. Needs `has_curfew: true`. Midnight is `'00:00'`. |
| `rating.score`, `rating.count` | 0–10 (one decimal), ≥ 0 | Both or neither. |
| `rating.highlights` | ≤ 3 codes | `food`, `atmosphere`, `architecture`, `location`, `hospitality`, `reachability`, `equipment`, `programme`, `value`. |
| `terms.<group>` | list of codes | See the vocabulary below. |
| `main_terms.<setting\|building\|suitable_for>` | one code | Must also be listed under `terms`. |
| `translations.<locale>` | `summary` ≤ 200 (required), `description`, `meta_description` ≤ 160, `parking_text` ≤ 250, `ambience` ≤ 60, `capacity_note`, `party_note`, `opening_hours` ≤ 250, `region` ≤ 60 | `de` is required; other locales only once the site serves them (today only `de`). |
| `photos[]` | `file`, `alt.<locale>` ≤ 700 | Order = display order. |
| `rooms[]` | `key`, `translations.<locale>.name` ≤ 100 (+ `description`), `capacity`, `area_m2` 1–50000, `height_m` (> 0, ≤ 30, two decimals), `floor_material`, `seating`, `photos` | Order = display order. A seating entry needs `min` or `max`. |
| `documents[]` | `file` (`.pdf`), `title.<locale>` ≤ 200 | `title.de` is required. |
| `reviews[]` | `first_name` ≤ 50, `role`, `month` `'YYYY-MM'`, `locale`, `text` ≤ 1000 | |

The wedding facts (`price_level`, `capacity.seated`, `capacity.standing`, `party`,
`contact.instagram`, the term groups `ceremony` to `language` and the four texts `ambience`,
`capacity_note`, `party_note`, `opening_hours`) are all optional. A missing one is unknown
and the detail page shows nothing for it. Nothing is inferred: set a fact only when the
venue stated it. The import applies a folder as a whole, so a field omitted on an update
resets to unknown.

### Vocabulary

| Group | Codes |
|---|---|
| `setting` | `river`, `lake`, `countryside`, `mountains`, `city` |
| `building` | `bar_club`, `farm_barn`, `mountain_hut`, `brewery_winery`, `medieval_castle`, `cafe`, `casino`, `digital_venue`, `experience_creative`, `event_hall`, `event_venue`, `factory_hall`, `mobile_venue`, `leisure_facility`, `gallery_studio`, `garden_greenhouse`, `hangar_shipyard`, `historic_site`, `wedding_venue`, `cave_fortress`, `hotel`, `cinema`, `congress_centre`, `cultural_centre`, `exhibition_hall`, `museum`, `open_air`, `party_room`, `restaurant`, `ship`, `castle`, `seminar_room`, `meeting_room`, `theatre`, `tram_train_bus`, `villa`, `forest_hut`, `tent`, `guild_hall` |
| `suitable_for` | `wedding`, `family_celebration`, `birthday`, `club_event`, `party_celebration`, `class_reunion`, `private_event`, `meeting`, `conference`, `congress`, `corporate_event`, `general_assembly`, `apero`, `team_event`, `christmas_party`, `concert`, `theatre`, `party`, `sports_event`, `trade_fair`, `public_event` |
| `infrastructure` | `wifi`, `air_conditioning`, `stage`, `shower`, `cloakroom`, `heating_ventilation`, `kitchen`, `backstage_rooms`, `lectern` |
| `furniture` | `flipchart`, `magnetic_board`, `moderation_kit`, `chairs`, `tables` |
| `technology` | `sound_system`, `projector_screen`, `interpreting_system`, `lighting_system`, `tv_screens` |
| `surroundings` | `garden`, `spa_wellness`, `terrace`, `playground`, `barbecue`, `golf` |
| `ceremony` | `civil_ceremony`, `free_ceremony`, `church_nearby`, `rain_alternative`, `registry_office`, `chapel` |
| `amenity` | `overnight`, `panorama`, `lake_view`, `dance_floor`, `kids_room`, `outdoor_dining`, `parking`, `parking_area`, `own_kitchen`, `catering_available`, `external_caterers`, `corkage`, `wheelchair_access`, `public_transport`, `tent` |
| `dining` | `vegetarian`, `vegan`, `gluten_free`, `organic`, `allergy_friendly`, `diabetic`, `halal`, `kosher`, `kids_menu`, `wedding_cake`, `buffet`, `grill`, `anything_possible` |
| `vendor` | `flowers_decor`, `photography`, `videography`, `officiant`, `dj`, `musicians`, `furniture`, `wedding_planner` |
| `usage` | `exclusive`, `multiple_weddings`, `wedding_and_regular` |
| `language` | `de`, `en`, `fr`, `it`, `es`, `pt`, `nl`, `cs`, `ru`, `hu`, `gsw`, `ro` |

An unknown code fails the venue with its position, e.g. `/terms/building/0: "palace" is not an allowed value`.

### YAML pitfalls

- Quote the ZIP (`'8001'`), the phone (`'+41441234567'`) and the review month (`'2025-06'`).
  Unquoted, YAML reads numbers (and full dates as timestamps) and the schema rejects them.
- Quote `'yes'` and `'no'` under `party.allowed`, and the curfew (`'02:00'`).
- Midnight is `'00:00'`. `24:00` is rejected, because the database time would come back as
  `00:00` of the next day.
- Unknown fields are errors, at every level (a typo like `sumary:` is reported, not ignored).
- Decimals use a dot (`6.5`), never a comma.

## Files

- Photos: `.jpg`, `.jpeg`, `.png`, `.webp` (any case). The import checks the bytes, not only
  the extension; GIF, AVIF and other formats fail. A generated folder never holds an AVIF
  photo: the export leaves it out and reports it.
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
| `--overwrite-cms-edits` | Also applies the folders of venues edited in the CMS since their last import, even when the folder is unchanged, and recreates venues deleted in the CMS (with a new number). **Every CMS edit of those venues is lost**, including photos, rooms, documents and reviews added by hand. With `--prune` it also unpublishes edited venues whose folder is gone. |
| `-v` | Also lists the unchanged venues. |

The exit code is `1` when any folder failed (the message lists `<key>: <problem>` for each),
otherwise `0`. Venues kept because of a CMS edit are listed after the summary and do not
change the exit code. To hide a venue by hand, set `status: unpublished`.

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
above about 40 MP needs more than the stock 256M (a 60 MP photo peaks at about 310 MiB). The
owner export leaves out photos above 100 MP, so a generated folder stays within that limit.
Run a full import with `--no-debug` in dev: with debug on, Doctrine keeps every query and
its backtrace for the whole command (about 2.5 KB each), and the 243 owner venues then peak
at about 505 MiB of the 512M.

## Editor support

Point the YAML language server (VS Code "YAML" extension, PhpStorm) at the schema with a
first line comment; the path is relative to the `venue.yaml`:

```
# yaml-language-server: $schema=<path to the checkout>/docs/content-format.schema.json
```

The editor then completes codes (`tier`, `terms.building`, …) and shows each field's limits.

## Generated folders

`app:legacy:export-content <csv> [path] [--dry-run]` turns the owner's Wix CSV into these
folders; `app:venues:import` then loads them. The CSV stays under the git-ignored
`content/owner/`.

The export owns exactly these paths and keeps every other key, so another tool or a hand can
add to a generated file:

- `name`, `capacity.max`, `address.canton`
- `contact.phone`, `contact.inquiry_email`, `contact.website`
- `translations.de.summary`, `translations.de.description`, `translations.de.region`

A value the CSV no longer holds removes its path. `tier` is written only when the file has
none, so a tier set later survives a re-export. Comments do not survive a re-export that
changes the file. The export removes no folder: one the CSV no longer has is reported as
stale. `photos/`, `rooms/` and `documents/` are never touched.

`app:owner:export-content <csv> [path] [--dry-run]` then adds the owner's wedding facts and
the Wix photos to the same folders. It writes folders only, never the database, and needs
the folder of each row, so the run order is:

```
make sf c="app:legacy:export-content content/owner/<export>.csv"
make sf c="app:owner:export-content content/owner/<export>.csv"
make sf c="--no-debug app:venues:import"
```

It owns exactly these paths and keeps every other key, including the paths above:

- `price_level`, `capacity.seated`, `capacity.standing`, `party`, `faq.own_catering`
- `terms.setting`, `terms.building`, `terms.suitable_for`, `terms.infrastructure`,
  `terms.technology`, `terms.surroundings`, `terms.ceremony`, `terms.amenity`,
  `terms.dining`, `terms.vendor`, `terms.usage`, `terms.language`
- `main_terms.setting`, `main_terms.building`, `main_terms.suitable_for`
- `translations.de.ambience`, `translations.de.capacity_note`,
  `translations.de.party_note`, `translations.de.opening_hours`,
  `translations.de.parking_text`
- `photos` and the files in `photos/`

It never touches `tier`, `faq.daylight`, `terms.furniture`, `rooms`, `documents`, `rating`,
`reviews` or `contact.instagram`. A value the CSV no longer holds removes its path, and a
section left empty goes with it; the codes in a group are sorted, so running either export
again, in either order, leaves the file byte-identical. Facts come only from the CSV's
fixed columns and its form answers, matched whole against fixed tables; anything else goes
to a `*_note` field or to the report, never into a code.

Photos are the cover (`Anzeigebild`) followed by the images in the text, in order, without
repeats. They are downloaded one at a time into `content/owner/photos/` (a cache: a second
run downloads nothing, and a failed download is retried on the next run) and linked or
copied into the folder's `photos/`; files there that are no longer listed are deleted. A
photo is left out and reported, never failing its venue, when it is AVIF, not a JPEG, PNG or
WebP, above 100 megapixels, not decodable, or the same image as an earlier one; the next
photo then becomes the cover.

The console prints counts and `<key>: <reason>` only. The details (unmapped answers, photos
left out, notes cut to 250 characters) go to `content/owner/export-report.md`, which
`--dry-run` also writes; a dry run writes nothing else and downloads nothing. The exit code is
`1` when a row has no folder or a download failed, otherwise `0`.

## What is not in the format

The format holds every venue column of the site and nothing else. Barrier-free access and
free parking are `amenity` codes (`wheelchair_access`, `parking`), not fields of their own.
