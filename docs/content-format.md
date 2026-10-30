# Venue content format

Real venues reach the site only through content folders (S8): one folder per venue with a
`venue.yaml` and its photos and PDFs. `app:venues:import` validates the folders and writes
them into the database; nothing else edits a content venue. This page is the reference for
writing folders by hand and for the legacy import (#21), which generates them.

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
- Recommendation for #21: `<ascii-name-slug>-<eventlokale-id>`, e.g. `schloss-beispiel-1093`.
  The id makes it unique (legacy slugs are not) and the name keeps it readable.

Room keys (`rooms[].key`) follow the same rules, are unique within the venue and never
change either: they keep a room's photos attached to it when rooms are reordered.

## Example

Every field, with fictional values. Only `name`, `tier`, `capacity.max`, `address.zip`,
`address.town`, `geo` and `translations.de.summary` are required.

```yaml
# yaml-language-server: $schema=../../../docs/content-format.schema.json
name: Schloss Beispiel                # ≤ 150 characters, not translated; the URL slug comes from it
tier: premium                         # beliebte_location | empfehlung | top_premium | premium | basic
status: published                     # optional: published (default) or unpublished
capacity:
  min: 20                             # optional, 1–50000, at most max
  max: 180                            # required, 1–50000
address:
  street: Seestrasse 1                # optional, ≤ 150
  zip: '8001'                         # four digits, quoted
  town: Zürich                        # official town name (Ortschaft)
  canton: zh                          # optional; only when ZIP and name match two towns
geo:
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
faq:                                  # optional; null or missing = unknown
  own_catering: allowed               # allowed | fixed_caterer
  daylight: true
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
| `capacity.min`, `capacity.max` | 1–50000 | `max` required; `min` ≤ `max`. |
| `address.street` | text ≤ 150 | |
| `address.zip` | `'NNNN'` | Quote it. Any ZIP of the town works, not only the lowest. |
| `address.town` | text | Official town name; matched exactly after folding case and umlauts (`Zürich` = `ZÜRICH` = `Zuerich`, but not `Zurich`). |
| `address.canton` | `zh`, `be`, … | Only when ZIP and name match more than one town (the import then says so). |
| `geo.lat`, `geo.lng` | decimal degrees | Inside Switzerland. |
| `distances.<station\|highway\|airport>` | `km` 0–500 (one decimal), `name` ≤ 150 | A name needs its km. |
| `contact.phone` | E.164 (`'+41…'`) | Quote it. |
| `contact.inquiry_email` | e-mail ≤ 254 | |
| `contact.website` | URL ≤ 500 | `http://` or `https://`. |
| `contact.contact_person` | text ≤ 150 | |
| `faq.own_catering` | `allowed`, `fixed_caterer`, null | |
| `faq.daylight` | true, false, null | |
| `rating.score`, `rating.count` | 0–10 (one decimal), ≥ 0 | Both or neither. |
| `rating.highlights` | ≤ 3 codes | `food`, `atmosphere`, `architecture`, `location`, `hospitality`, `reachability`, `equipment`, `programme`, `value`. |
| `terms.<group>` | list of codes | See the vocabulary below. |
| `main_terms.<setting\|building\|suitable_for>` | one code | Must also be listed under `terms`. |
| `translations.<locale>` | `summary` ≤ 200 (required), `description`, `meta_description` ≤ 160, `parking_text` ≤ 250 | `de` is required; other locales only once the site serves them (today only `de`). |
| `photos[]` | `file`, `alt.<locale>` ≤ 700 | Order = display order. |
| `rooms[]` | `key`, `translations.<locale>.name` ≤ 100 (+ `description`), `capacity`, `area_m2` 1–50000, `height_m` (> 0, ≤ 30, two decimals), `floor_material`, `seating`, `photos` | Order = display order. A seating entry needs `min` or `max`. |
| `documents[]` | `file` (`.pdf`), `title.<locale>` ≤ 200 | `title.de` is required. |
| `reviews[]` | `first_name` ≤ 50, `role`, `month` `'YYYY-MM'`, `locale`, `text` ≤ 1000 | |

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

An unknown code fails the venue with its position, e.g. `/terms/building/0: "palace" is not an allowed value`.

### YAML pitfalls

- Quote the ZIP (`'8001'`), the phone (`'+41441234567'`) and the review month (`'2025-06'`).
  Unquoted, YAML reads numbers (and full dates as timestamps) and the schema rejects them.
- Unknown fields are errors, at every level (a typo like `sumary:` is reported, not ignored).
- Decimals use a dot (`6.5`), never a comma.

## Files

- Photos: `.jpg`, `.jpeg`, `.png`, `.webp` (any case). The import checks the bytes, not only
  the extension; GIF and other formats fail.
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
make sf c="app:venues:import [path] [--dry-run] [--only=key] [--prune] [--force]"
```

1. Reads every folder under `venues/` (in name order), validates it against the schema and
   the rules above. A folder with problems fails **alone**, with every problem listed; the
   others are imported. One folder is one transaction.
2. A folder whose content (fields and file bytes) is unchanged since the last import is
   skipped: running the import twice changes nothing.
3. Creates or updates the venue by key. The venue number (used in saved favourites) never
   changes. Rooms keep their identity by key, photos and documents by their bytes.
4. Slugs: the name's slug; when another venue has it (also as an old slug), the town is
   appended (`schloss-beispiel-zuerich`), then a counter (`-2`, `-3`). Old slugs are kept so
   their URLs redirect; renaming back takes the old slug back.
5. Towns are resolved by ZIP and name; no match or several matches fail the venue.
6. Originals are copied to `MEDIA_ROOT/content/<key>/<sha256>.<ext>`; the web sizes are
   generated only for new photos. Files of removed photos and documents are deleted after
   the database commit.
7. Each run (except a dry run) is recorded in the `content_imports` table with its counts
   and errors. The pages of every created, updated or unpublished venue are purged from the
   HTTP cache after its commit, and the listings once per run that changed something.

| Option | Effect |
|---|---|
| `path` | Content root that contains `venues/` (default `CONTENT_ROOT`). |
| `--dry-run` | Validates and reports what would be created, updated, unchanged, failed (and unpublished with `--prune`); writes nothing, records nothing. |
| `--only=<key>` | Imports one folder. |
| `--prune` | Unpublishes content venues whose folder is gone (files kept; the folder coming back publishes it again). A folder that exists but is invalid is not pruned. Not combinable with `--only`. |
| `--force` | Applies every folder even when unchanged (photos are still not regenerated). |
| `-v` | Also lists the unchanged venues. |

The exit code is `1` when any folder failed (the message lists `<key>: <problem>` for each),
otherwise `0`. To hide a venue by hand, set `status: unpublished`.

Run one import at a time; there is no lock.

## Editor support

Point the YAML language server (VS Code "YAML" extension, PhpStorm) at the schema with a
first line comment; the path is relative to the `venue.yaml`:

```
# yaml-language-server: $schema=<path to the checkout>/docs/content-format.schema.json
```

The editor then completes codes (`tier`, `terms.building`, …) and shows each field's limits.

## What is not in the format

The format holds every venue column of the site and nothing else. These legacy fields have
no place, so #21 drops them: single and double rooms, barrier-free, free parking, room
width/length/daylight, ground plans, YouTube links and the header video, photo type tags,
`boost` and `label_text` (the tier carries the listing order). #21 must also clean values
the schema rejects: capacity 0, coordinates 0/0, summaries over 200 characters, room
heights over 30 m, rooms without a name, numeric airport/highway "names" and GIF photos.
