-- Demo data ×10 for the perf stack (S11, PERF-1; plan PD4): clones every venue 9 times, with its
-- translations, terms, venue-level photos and their rights grants. Run by `make perf-seed TIMES=10` on
-- a fresh dump of the lane's seeded database; never against the lane or the test database.
--
-- Columns are listed from information_schema (not generated, not identity), so `number` and
-- `tier_rank` are left to the database and a new column needs no change here. Rooms are not
-- cloned, so room photos are left out too: a clone's card shows the same photos as its source.
-- A photo is public only with a live grant (G1, Venues\PublicPhotoPolicy), so each cloned photo
-- gets a copy of its source's live grant, and a source without one is cloned without one.
-- ListingQueryPerformanceTest keeps its own PHP clone (the perf stack has no dev packages).
DO $$
DECLARE
    copies CONSTANT int := 9;
    original int;
    photos int;
    grants int;
    cols text;
    sel text;
BEGIN
    SELECT count(*) INTO original FROM venues;
    SELECT count(*) INTO photos FROM venue_photos WHERE room_id IS NULL;
    SELECT count(*) INTO grants
      FROM media_rights_grants g JOIN venue_photos p ON p.id = g.venue_photo_id
     WHERE g.revoked_at IS NULL AND p.room_id IS NULL;
    IF original = 0 THEN
        RAISE EXCEPTION 'times-ten.sql: no venues to clone';
    END IF;

    CREATE TEMP TABLE clone_map ON COMMIT DROP AS
        SELECT v.id AS src, gen_random_uuid() AS id, n
        FROM venues v CROSS JOIN generate_series(1, copies) AS n;

    -- venues: new id, numbered name.
    SELECT string_agg(quote_ident(column_name), ', ' ORDER BY ordinal_position),
           string_agg(CASE column_name
                          WHEN 'id' THEN 'm.id'
                          WHEN 'name' THEN $q$v.name || ' ' || m.n$q$
                          ELSE 'v.' || quote_ident(column_name)
                      END, ', ' ORDER BY ordinal_position)
      INTO cols, sel
      FROM information_schema.columns
     WHERE table_schema = current_schema() AND table_name = 'venues'
       AND is_generated = 'NEVER' AND is_identity = 'NO';
    EXECUTE format('INSERT INTO venues (%s) SELECT %s FROM venues v JOIN clone_map m ON m.src = v.id', cols, sel);

    -- venue_translations: new id, numbered slug (unique per locale).
    SELECT string_agg(quote_ident(column_name), ', ' ORDER BY ordinal_position),
           string_agg(CASE column_name
                          WHEN 'id' THEN 'gen_random_uuid()'
                          WHEN 'venue_id' THEN 'm.id'
                          WHEN 'slug' THEN $q$v.slug || '-' || m.n$q$
                          ELSE 'v.' || quote_ident(column_name)
                      END, ', ' ORDER BY ordinal_position)
      INTO cols, sel
      FROM information_schema.columns
     WHERE table_schema = current_schema() AND table_name = 'venue_translations'
       AND is_generated = 'NEVER' AND is_identity = 'NO';
    EXECUTE format('INSERT INTO venue_translations (%s) SELECT %s FROM venue_translations v JOIN clone_map m ON m.src = v.venue_id', cols, sel);

    -- venue_terms: only the venue changes.
    SELECT string_agg(quote_ident(column_name), ', ' ORDER BY ordinal_position),
           string_agg(CASE column_name WHEN 'venue_id' THEN 'm.id' ELSE 'v.' || quote_ident(column_name) END, ', ' ORDER BY ordinal_position)
      INTO cols, sel
      FROM information_schema.columns
     WHERE table_schema = current_schema() AND table_name = 'venue_terms'
       AND is_generated = 'NEVER' AND is_identity = 'NO';
    EXECUTE format('INSERT INTO venue_terms (%s) SELECT %s FROM venue_terms v JOIN clone_map m ON m.src = v.venue_id', cols, sel);

    -- venue_photos: each venue-level photo once per clone, column-agnostic through jsonb, so the
    -- NOT NULL media columns are filled. Files live under public/media/<number>/<photo id>-*, so a
    -- clone's photos have no files: k6 fetches HTML only, and Lighthouse runs on demo ×1. The new
    -- ids are fixed first (photo_map), because the grants below have to point at them.
    CREATE TEMP TABLE photo_map ON COMMIT DROP AS
        SELECT p.id AS src, gen_random_uuid() AS id, m.id AS venue_id
        FROM venue_photos p JOIN clone_map m ON m.src = p.venue_id
        WHERE p.room_id IS NULL;
    SELECT string_agg(quote_ident(column_name), ', ' ORDER BY ordinal_position),
           string_agg('r.' || quote_ident(column_name), ', ' ORDER BY ordinal_position)
      INTO cols, sel
      FROM information_schema.columns
     WHERE table_schema = current_schema() AND table_name = 'venue_photos'
       AND is_generated = 'NEVER' AND is_identity = 'NO';
    EXECUTE format(
        $f$INSERT INTO venue_photos (%s)
           SELECT %s
           FROM venue_photos p
           JOIN photo_map pm ON pm.src = p.id
           CROSS JOIN LATERAL jsonb_populate_record(
               NULL::venue_photos,
               to_jsonb(p) || jsonb_build_object('id', pm.id, 'venue_id', pm.venue_id, 'room_id', NULL)
           ) r$f$,
        cols, sel);

    -- media_rights_grants: the live grant of each source photo, once per clone: a new id (uuidv7, as the
    -- application writes it), the clone's venue and the clone's photo. A revoked grant has no photo, so
    -- it is not copied.
    SELECT string_agg(quote_ident(column_name), ', ' ORDER BY ordinal_position),
           string_agg('r.' || quote_ident(column_name), ', ' ORDER BY ordinal_position)
      INTO cols, sel
      FROM information_schema.columns
     WHERE table_schema = current_schema() AND table_name = 'media_rights_grants'
       AND is_generated = 'NEVER' AND is_identity = 'NO';
    EXECUTE format(
        $f$INSERT INTO media_rights_grants (%s)
           SELECT %s
           FROM media_rights_grants g
           JOIN photo_map pm ON pm.src = g.venue_photo_id
           CROSS JOIN LATERAL jsonb_populate_record(
               NULL::media_rights_grants,
               to_jsonb(g) || jsonb_build_object('id', uuidv7(), 'venue_id', pm.venue_id, 'venue_photo_id', pm.id)
           ) r
           WHERE g.revoked_at IS NULL$f$,
        cols, sel);

    IF (SELECT count(*) FROM venues) <> original * (copies + 1) THEN
        RAISE EXCEPTION 'times-ten.sql: expected % venues, got %', original * (copies + 1), (SELECT count(*) FROM venues);
    END IF;
    IF (SELECT count(*) FROM venue_photos WHERE room_id IS NULL) <> photos * (copies + 1) THEN
        RAISE EXCEPTION 'times-ten.sql: expected % venue photos, got %', photos * (copies + 1), (SELECT count(*) FROM venue_photos WHERE room_id IS NULL);
    END IF;
    IF (SELECT count(*) FROM media_rights_grants g JOIN venue_photos p ON p.id = g.venue_photo_id WHERE g.revoked_at IS NULL AND p.room_id IS NULL) <> grants * (copies + 1) THEN
        RAISE EXCEPTION 'times-ten.sql: expected % live grants, got %', grants * (copies + 1),
            (SELECT count(*) FROM media_rights_grants g JOIN venue_photos p ON p.id = g.venue_photo_id WHERE g.revoked_at IS NULL AND p.room_id IS NULL);
    END IF;
    RAISE NOTICE 'times-ten.sql: % venues, % venue photos, % live grants', original * (copies + 1), photos * (copies + 1), grants * (copies + 1);
END
$$;

ANALYZE venues, venue_translations, venue_terms, venue_photos, media_rights_grants;
