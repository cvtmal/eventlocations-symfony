-- Perf stack only (S11, PERF-1; run by `make perf-seed` before tests/Load/times-ten.sql): makes the
-- Zürich canton page live at prod's threshold. Demo ×1 has at most 3 published venues per canton, and
-- %landing.min_venues% is 5 outside dev, so on the perf stack (APP_ENV=prod) every canton page is a
-- 404. This moves the lowest-numbered published venues outside Zürich that have a town into the most
-- populous Zürich towns, until Zürich has 5. Their photos keep their ids and files, so Lighthouse
-- measures real images. Never run against the lane or the test database.
DO $$
DECLARE
    needed int;
BEGIN
    SELECT 5 - count(*) INTO needed
      FROM venues v
      JOIN venue_translations t ON t.venue_id = v.id AND t.locale = 'de'
     WHERE v.status = 'published' AND v.canton_code = 'zh';
    IF needed <= 0 THEN
        RETURN;
    END IF;

    WITH movers AS (
        SELECT v.id, row_number() OVER (ORDER BY v.number) AS n
          FROM venues v
          JOIN venue_translations t ON t.venue_id = v.id AND t.locale = 'de'
         WHERE v.status = 'published' AND v.town_id IS NOT NULL AND v.canton_code <> 'zh'
         ORDER BY v.number
         LIMIT needed
    ), targets AS (
        SELECT tw.id, tw.zip, tw.lat, tw.lng, row_number() OVER (ORDER BY tw.population DESC NULLS LAST, tw.name) AS n
          FROM towns tw
         WHERE tw.canton_code = 'zh'
           AND NOT EXISTS (SELECT 1 FROM venues o WHERE o.town_id = tw.id)
         ORDER BY tw.population DESC NULLS LAST, tw.name
         LIMIT needed
    )
    UPDATE venues v
       SET town_id = tg.id, zip = tg.zip, canton_code = 'zh', lat = tg.lat, lng = tg.lng, coordinates_from_town = FALSE
      FROM movers m
      JOIN targets tg ON tg.n = m.n
     WHERE v.id = m.id;

    IF (SELECT count(*) FROM venues v JOIN venue_translations t ON t.venue_id = v.id AND t.locale = 'de'
         WHERE v.status = 'published' AND v.canton_code = 'zh') < 5 THEN
        RAISE EXCEPTION 'live-canton.sql: Zürich still has fewer than 5 published venues';
    END IF;
    RAISE NOTICE 'live-canton.sql: moved % venues to Zürich', needed;
END
$$;

ANALYZE venues;
