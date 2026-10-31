---
paths:
  - "src/Media/**"
  - "assets/styles/media/**"
  - "translations/media*"
  - "tests/Support/Media/**"
  - "tests/{Unit,Integration,Application}/Media/**"
---

### Media

Image variant generation, `<picture>` Twig helper.

**Built in #5 (`venue-media`).** Media depends on nothing but Shared; it knows paths and pixels, never venues.

- **Classes:** `Media\ImageVariantGenerator::generate(string $sourceFile, string $targetDirectory, string $basename): GeneratedImage` renders the S9 variants with gd at import time (never on request): widths 360/640/960/1280/1920 up to the EXIF-oriented original's width (a single `[<width>]` below 360), AVIF q52 s6, WebP 80, progressive JPEG 82, as `<dir>/<basename>-<w>.<ext>`. Orientation applied, metadata and ICC stripped (no conversion), alpha flattened on white, every file checked (`imageavif()` returns true on failure) and stale widths removed. Formats are detected at runtime (`availableFormats()`); without AVIF a host writes WebP + JPEG and the stored `formats` say so. `Media\MediaPublisher` (`copyVariants()`, `publishDocument()`, `removeVenue()`), `Media\MediaPaths` (all paths and `documentUrl()`), value objects `Media\ValueObject\{ImageFormat, ImageVariants, GeneratedImage, Picture}`, exceptions `Media\Exception\{MissingOriginal, UnreadableImage, InvalidMediaPath}` (an encoder failure is a `\RuntimeException`).
- **Paths:** originals under `MEDIA_ROOT` (env, `app.media_root`; dev `var/media`, test `var/test-media/originals`); `venue_photos.original_path` and `venue_documents.path` are relative to it. Variants at `public/media/<venues.number>/<photo-id>-<w>.<avif|webp|jpg>`, PDFs at `public/media/<number>/<document-id>.pdf` (`app.media_public_dir`; test `var/test-media/public`), URLs `/media/…`, served statically by nginx, git-ignored.
- **Two-step photo write:** the columns are NOT NULL and the id exists only after the constructor, so generate into a staging directory first, create `VenuePhoto(…, $generatedImage)`, then `MediaPublisher::copyVariants()` under the id. A changed original gets a new photo id.
- **Twig:** `{{ venue_picture(picture, sizes='(min-width: 900px) 520px, 100vw', loading='lazy', priority=false, widths=null, class='') }}` renders `<span class="picture">` + SVG placeholder (`fill` = dominant colour, no `style`) + `<picture>` with AVIF/WebP `<source>`s for the stored formats and a JPEG `<img width height>` (the original's size). Cards pass `widths=[360, 640, 960]`; the one LCP image `priority=true`. Read models build `Picture` from a row with `ImageVariants::fromJson($row['variants'])`; a photo without alt row uses `'picture.alt_fallback'|trans({name, position}, 'media')`. `{{ document_url(venueNumber, documentId) }}` → `/media/<n>/<id>.pdf`. Styles: `assets/styles/media/_picture.scss`.
- **Regenerate:** `make sf c="app:media:regenerate [--venue=<number>]"` (lives in Venues) re-renders variants in place and re-publishes PDFs; it continues past missing originals and exits 1 if any were skipped. URLs stay the same, so `/media` is not `immutable` across it (#19).
- **Demo media:** `App\Story\DemoMedia` draws 30 originals (`MEDIA_ROOT/demo/photo-NN.jpg`) and caches their variants in `var/demo-media/<fingerprint+hash>/`, shared by dev and test. The first `make seed` on a checkout is cold (~22 s on an M4 Pro), later ones copy only (~10 s).
- **Tests:** test images are drawn at runtime (`tests/Support/Media/TestImages.php`, no binary fixtures); media tests clean up with `MediaFiles::removeDirectory()`.

**Built in #16.** `Picture::shareImage(): ShareImage` (`url`, `width`, `height`): JPEG at `ImageVariants::shareWidth()`, the smallest width ≥ 1200, else the largest. It is the one source of a photo's public share URL (`og:image`, JSON-LD, sitemap).
