# Back-office design reference

The target look for the back office at `/admin` (`docs/rpi-cycles.md` S12, Q11). Every back-office cycle (#22–#30) compares its screens with it at 1440 px and keeps them usable at 390 px.

- **Live design (clickable):** https://claude.ai/artifact/LDRuCKBiQoQkUXNAG7vEtY. Open it with Claude in Chrome, or read it with the Artifact tool's `read` action. Press Play on an artboard to click through: the sidebar links the screens, and the photo grid, composer tabs, pipeline "Weiter →" and inbox list react.
- **Source in this folder:** one `*.dc.html` per artboard plus `canvas.json`. They need the design canvas's runtime to render, so read them for markup, values and copy rather than opening them in a browser. If the live design and these files differ, the live design wins; copy it back here.
- **Status:** drafted in the planning session on 2026-10-30. The owner reviews it before #22 starts. Changes are recorded in #22's or #23's Outcome.
- **Screenshots:** at its start, #22 saves a 1440 px PNG of each artboard here (`<artboard>.png`), taken from the live design.

## Screens

| Artboard | Screen | Cycle |
|---|---|---|
| `Main.dc.html` | Dashboard (`/admin`): KPI cards and widgets for inquiries, tasks, renewals, incomplete venues | #22 (shell), widgets from #24, #27–#29 |
| `Sidebar.dc.html` | Sidebar component: sections Übersicht, CMS, CRM, Einstellungen; active state; badges; user | #22 |
| `Locations.dc.html` | Venue list: status tabs, search, filters, active filter chip, table, pagination | #24 |
| `LocationEdit.dc.html` | Venue editor: record header, record tabs, one card per section with its own "Speichern", inline error, side panels (publish checklist, CRM panel, history) | #24, panel from #27 |
| `Photos.dc.html` | Photo manager island: drop zone, upload queue with progress and error, sortable grid with keyboard ↑ ↓, details panel | #25 |
| `Company.dc.html` | CRM record page: properties (one shown in inline-edit state), quick actions, activity composer (note / e-mail / call / task), timeline incl. system events, associations | #27, e-mail #30 |
| `Pipeline.dc.html` | Deals board: columns with count and sum, deal cards, keyboard "Weiter →", board/list switch | #28 |
| `Inbox.dc.html` | Inquiry inbox: status tabs, list with unread dot, detail with delivery status | #29 |
| `Login.dc.html` | Login, then the TOTP step with an error state | #22 |
| `Tokens.dc.html` | Colours, type, spacing, radii and the component vocabulary | #22 (tokens), #23 (components) |

## Tokens (become `--a-*` in `assets/styles/admin.scss`)

| Token | Value | Use |
|---|---|---|
| `--a-bg` | `#F6F5F2` | page background |
| `--a-surface` | `#FFFFFF` | cards, header, tables |
| `--a-surface-muted` | `#FBFAF8` | card footers, table head, drop zone |
| `--a-ink` | `#1B1B1B` | text, sidebar, primary buttons, selected chips |
| `--a-muted` | `#66645F` | secondary text (5.7:1 on white) |
| `--a-line` / `--a-line-soft` | `#E7E4DF` / `#F0EDE8` | borders, row dividers |
| `--a-field` | `#D6D2CB` | input and button borders |
| `--a-accent` | `#FF5A75` | the one accent: active nav icon, count badges, unread dot; text on it is `#121212` |
| `--a-link` | `#B03A50` | links (5.9:1 on white) |
| `--a-danger` | `#A3261D` | errors, destructive buttons |
| `--a-nav-text` / `--a-nav-muted` / `--a-nav-active` | `#D6D3CE` / `#A9A6A0` / `#2F2F2F` | sidebar |
| status pairs | green `#E3F3EA`/`#17603D`, amber `#FBEFD5`/`#7A4E00`, blue `#E6EDFA`/`#1F4C99`, grey `#EEECE8`/`#4A4945`, pink `#FFE4E9`/`#9E2F44`, red `#FBE3E1`/`#A3261D` | pills (background/text) |

- **Type:** Sofia Sans (the public site's self-hosted files) 400/600/700. h1 28/34 700, h2 17 700, body 14/20, fields 15, labels 13 600, small 12 600.
- **Spacing:** 4, 8, 12, 16, 20, 24, 32. **Radii:** 6 (small), 8 (fields, buttons), 10 (tiles), 12 (cards), pill.
- **Focus:** a 2 px `--a-ink` outline with a 2 px offset. Never remove it.
- **Tiers as pills:** Beliebte Location pink, Empfehlung amber, Top Premium blue, Premium green, Standard grey.
- **Layout:** a 248 px sidebar plus fluid content, wrapping below 808 px. Record pages have properties (≈ 300 px), content (fluid) and associations (≈ 300 px), which stack on narrow screens.

The demo names, addresses and people in the design are fictional placeholders; e-mail addresses use `example.ch` or `[location]`.
