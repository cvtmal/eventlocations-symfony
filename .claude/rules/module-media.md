---
paths:
  - "src/Media/**"
  - "assets/styles/media/**"
  - "translations/media*"
  - "tests/Support/Media/**"
  - "tests/{Unit,Integration,Application}/Media/**"
---
Append current-state lines for your cycle at the end (last commit).

### Media

Image variants, `<picture>` and regeneration. Read it by hand before changing variants, `/media` paths or `venue_picture`. Depends on Shared only: paths and pixels, never venues.

## Variants, paths, regeneration

- **Variants:** `ImageVariantGenerator::generate()` renders with gd when a photo is written (never on a public request): widths 360/640/960/1280/1920 up to the original's width (narrower than 360: one variant at its own width), never upscaled, alpha flattened onto white, stale widths of the basename removed, AVIF q52 s6, WebP 80, progressive JPEG 82, EXIF/ICC stripped. Each file is checked (`imageavif()` returns true on failure). JPEG always, AVIF and WebP where gd has them.
- **Paths:** originals in `MEDIA_ROOT` (`app.media_root`); `venue_photos.original_path` and `venue_documents.path` are relative to it; variants `public/media/<venues.number>/<photo-id>-<w>.<ext>` and PDFs `<document-id>.pdf` there (`app.media_public_dir`); `/media` is never `immutable` (regenerate rewrites the same URLs).
- **Classes:** `MediaPublisher` (`copyVariants()`, `publishDocument()`, `removeVenue()`), `MediaPaths` (all paths; `documentUrl()` backs Twig `document_url()`), VOs `ImageFormat`, `ImageVariants`, `GeneratedImage`, `Picture`, exceptions `MissingOriginal`, `UnreadableImage`, `InvalidMediaPath` (an encoder failure is a `\RuntimeException`).
- **Photo write** (`Venues\PhotoPipeline`): the id exists only after the constructor, so generate into staging, create `VenuePhoto`, then `copyVariants()` under the id; a changed original gets a new id.
- **Regenerate:** `app:media:regenerate [--venue=<n>]` (in Venues) re-renders in place, skips missing originals, exits 1 if any were skipped.

## Rendering and tests

- **`venue_picture()`:** the placeholder is an SVG `fill`, never a `style` (CSP); usage rules: `frontend.md`. `Picture` built from `ImageVariants::fromJson($row['variants'])`; no alt row: `picture.alt_fallback` (`media`).
- **`Picture::shareImage()`:** the one source of share URLs (`og:image`, JSON-LD, sitemap): JPEG at the smallest width >= 1200, else the largest.
- **Demo, tests:** `App\Story\DemoMedia` draws 30 demo originals; tests draw images at runtime (`TestImages`), no binary fixtures.
