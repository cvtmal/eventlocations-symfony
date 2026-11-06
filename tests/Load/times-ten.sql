-- Demo data ×10 for the perf stack (S11, PERF-1; plan PD4): clones every venue 9 times, with its
-- translations, terms and venue-level photos. Run by `make perf-seed TIMES=10` on
-- a fresh dump of the lane's seeded database; never against the lane or the test database.
--
-- Columns are listed from information_schema (not generated, not identity), so `number` and
-- `tier_rank` are left to the database and a new column needs no change here. Rooms are not
-- cloned, so room photos are left out too: a clone's card shows the same photos as its source.
-- ListingQueryPerformanceTest keeps its own PHP clone (the perf stack has no dev packages).
DO $$
DECLARE
    copies CONSTANT int := 9;
    original int;
    photos int;
    cols text;
    sel text;
BEGIN
    SELECT count(*) INTO original FROM venues;
    SELECT count(*) INTO photos FROM venue_photos WHERE room_id IS NULL;
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
    -- clone's photos have no files: k6 fetches HTML only, and Lighthouse runs on demo ×1.
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
           JOIN clone_map m ON m.src = p.venue_id
           CROSS JOIN LATERAL jsonb_populate_record(
               NULL::venue_photos,
               to_jsonb(p) || jsonb_build_object('id', gen_random_uuid(), 'venue_id', m.id, 'room_id', NULL)
           ) r
           WHERE p.room_id IS NULL$f$,
        cols, sel);

    IF (SELECT count(*) FROM venues) <> original * (copies + 1) THEN
        RAISE EXCEPTION 'times-ten.sql: expected % venues, got %', original * (copies + 1), (SELECT count(*) FROM venues);
    END IF;
    IF (SELECT count(*) FROM venue_photos WHERE room_id IS NULL) <> photos * (copies + 1) THEN
        RAISE EXCEPTION 'times-ten.sql: expected % venue photos, got %', photos * (copies + 1), (SELECT count(*) FROM venue_photos WHERE room_id IS NULL);
    END IF;
    RAISE NOTICE 'times-ten.sql: % venues, % venue photos', original * (copies + 1), photos * (copies + 1);
END
$$;

ANALYZE venues, venue_translations, venue_terms, venue_photos;
