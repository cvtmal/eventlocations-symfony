<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues\SiteImport;

use App\Shared\Http\SafeUrl;
use App\Venues\Exception\UnreadablePage;
use App\Venues\SiteImport\ParsedPage;
use App\Venues\SiteImport\SitePageParser;
use App\Venues\ValueObject\CandidateOrigin;
use App\Venues\ValueObject\ImageCandidate;
use PHPUnit\Framework\TestCase;

/**
 * The details of reading one page that the fixture sites do not pin: encodings, base URL, phone and address
 * formats, what counts as a link worth following and as an icon.
 */
final class SitePageParserTest extends TestCase
{
    private function parse(string $html, ?string $charset = 'utf-8', string $url = 'https://www.beispiel-venue.example/seite/'): ParsedPage
    {
        return (new SitePageParser())->parse($html, $charset, SafeUrl::parse($url));
    }

    /**
     * @return list<mixed> the values of one key, best first
     */
    private static function values(ParsedPage $page, string $key): array
    {
        return array_column($page->facts->toArray()[$key] ?? [], 'value');
    }

    /**
     * @return list<string>
     */
    private static function imageUrls(ParsedPage $page): array
    {
        return array_map(static fn (ImageCandidate $image): string => $image->imageUrl, $page->images);
    }

    public function test_latin1_pages_are_decoded_by_header_and_by_meta_tag(): void
    {
        $latin1 = mb_convert_encoding('<html><head><title>Küche & Säle Zürich</title></head><body><h1>Grüezi</h1></body></html>', 'ISO-8859-1', 'UTF-8');

        self::assertSame('Küche & Säle Zürich', self::values($this->parse($latin1, 'ISO-8859-1'), 'name')[0]);
        self::assertSame('Küche & Säle Zürich', self::values($this->parse($latin1, null), 'name')[0], 'no header, no meta: not UTF-8, so Windows-1252');

        $withMeta = mb_convert_encoding('<html><head><meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1"><title>Säle</title></head></html>', 'ISO-8859-1', 'UTF-8');
        self::assertSame('Säle', self::values($this->parse($withMeta, null), 'name')[0]);
    }

    public function test_broken_bytes_and_an_unknown_charset_do_not_throw(): void
    {
        self::assertSame('Säle', self::values($this->parse("<title>S\xE4le</title>", 'utf-8'), 'name')[0]);
        self::assertSame('Säle', self::values($this->parse("<title>S\xE4le</title>", 'no-such-charset'), 'name')[0]);
        self::assertSame([], self::values($this->parse(''), 'name'));
        self::assertSame([], $this->parse('<<<>>> not html at all')->images);
    }

    public function test_relative_references_follow_the_base_element(): void
    {
        $page = $this->parse('<html><head><base href="https://www.beispiel-venue.example/medien/"><link rel="canonical" href="../start"></head><body><img src="saal.jpg" width="800" height="600"></body></html>');

        self::assertSame(['https://www.beispiel-venue.example/medien/saal.jpg'], self::imageUrls($page));
        self::assertSame(['https://www.beispiel-venue.example/start'], self::values($page, 'canonical_url'));
    }

    public function test_phone_numbers_are_normalised_and_prices_and_years_are_not_phone_numbers(): void
    {
        $html = '<body><p>Tel. 044 555 01 23</p><p>Mobil: +41 (0)79 555 01 99</p><p>Ab CHF 2024 20 30 40 pro Person</p><a href="tel:0041445550123">anrufen</a><p>Baujahr 1868, 120 Personen</p></body>';

        $phones = self::values($this->parse($html), 'phone');

        self::assertContains('+41 44 555 01 23', $phones);
        self::assertContains('+41 79 555 01 99', $phones);
        self::assertSame('+41 44 555 01 23', $phones[0], 'the tel: link is the most confident');
        self::assertCount(2, $phones);
    }

    public function test_emails_from_links_and_text_without_placeholders_and_file_names(): void
    {
        $html = '<body><a href="mailto:Events@Beispiel-Venue.example?subject=Anfrage">Mail</a><p>logo@2x.png info@example.com hallo@beispiel-venue.example</p></body>';

        self::assertSame(['events@beispiel-venue.example', 'hallo@beispiel-venue.example'], self::values($this->parse($html), 'email'));
    }

    public function test_swiss_addresses_in_one_line_and_in_two_lines_but_not_a_post_box_or_a_foreign_zip(): void
    {
        $html = '<body><footer><p>Schlossweg 3, 6003 Luzern</p><p>Postfach 123</p><p>6003 Luzern</p>'
            .'<p>Seestrasse 9<br>8002 Zürich</p><p>Hauptstrasse 5, 12345 Berlin</p><p>Rue du Lac 4, CH-1006 Lausanne</p></footer></body>';

        $addresses = array_map(static fn (array $address): string => $address['street'].'|'.$address['postal_code'].'|'.$address['locality'], self::values($this->parse($html), 'address'));

        self::assertContains('Schlossweg 3|6003|Luzern', $addresses);
        self::assertContains('Seestrasse 9|8002|Zürich', $addresses);
        self::assertContains('Rue du Lac 4|1006|Lausanne', $addresses);
        self::assertCount(3, $addresses);
    }

    public function test_json_ld_of_other_types_is_ignored_and_broken_json_does_not_throw(): void
    {
        $html = '<head>'
            .'<script type="application/ld+json">{"@type":"BreadcrumbList","name":"Pfad","telephone":"044 111 11 11"}</script>'
            .'<script type="application/ld+json">{ this is not json</script>'
            .'<script type="application/ld+json">[{"@type":"Restaurant","name":"Sonnenhof Muster","maximumAttendeeCapacity":"80"}]</script>'
            .'</head>';

        $page = $this->parse($html);

        self::assertSame(['Sonnenhof Muster'], self::values($page, 'name'));
        self::assertSame([80], self::values($page, 'capacity'));
        self::assertSame([], self::values($page, 'phone'));
    }

    public function test_icons_logos_tiny_pictures_and_data_uris_are_left_out_and_counted(): void
    {
        $html = '<body>'
            .'<img src="/img/logo.png" width="400" height="200">'
            .'<img src="/img/icon-facebook.png" width="400" height="300">'
            .'<img src="/img/sprite.png">'
            .'<img src="/img/pfeil.svg">'
            .'<img src="/media/saal.jpg" width="150" height="100">'
            .'<img src="data:image/png;base64,AAAA">'
            .'<img src="/media/saal-gross.jpg" alt="Der Saal" width="1200" height="800">'
            .'<img src="/media/saal-gross-300x200.jpg" alt="Der Saal" width="300" height="200">'
            .'<img src="/media/ohne-groesse.jpeg">'
            .'</body>';

        $page = $this->parse($html);

        self::assertSame(['https://www.beispiel-venue.example/media/saal-gross.jpg', 'https://www.beispiel-venue.example/media/ohne-groesse.jpeg'], self::imageUrls($page));
        self::assertSame(5, $page->skippedImages, 'logo, facebook icon, sprite, svg and the 150 x 100 picture');
        self::assertSame(CandidateOrigin::Img, $page->images[0]->origin);
        self::assertSame(1200, $page->images[0]->width);
        self::assertSame('Der Saal', $page->images[0]->alt);
    }

    public function test_the_widest_srcset_entry_is_the_candidate_and_a_picture_gives_one_candidate(): void
    {
        $html = '<body><h2>Terrasse</h2>'
            .'<img src="/m/klein.jpg" srcset="/m/klein.jpg 480w, /m/mittel.jpg 960w, /m/gross.jpg 1920w" alt="Terrasse">'
            .'<picture><source srcset="/m/p-800.webp 800w, /m/p-1600.webp 1600w"><img src="/m/p.jpg" alt="Garten"></picture>'
            .'</body>';

        $page = $this->parse($html);

        self::assertSame(['https://www.beispiel-venue.example/m/gross.jpg', 'https://www.beispiel-venue.example/m/p-1600.webp'], self::imageUrls($page));
        self::assertSame([CandidateOrigin::Srcset, CandidateOrigin::Picture], array_map(static fn (ImageCandidate $image): CandidateOrigin => $image->origin, $page->images));
        self::assertSame(['Terrasse', 'Garten'], array_map(static fn (ImageCandidate $image): ?string => $image->alt, $page->images));
        self::assertSame(['Terrasse', 'Terrasse'], array_map(static fn (ImageCandidate $image): ?string => $image->context, $page->images), 'the context is the heading before the picture');
    }

    public function test_a_page_with_thousands_of_images_is_cut_off(): void
    {
        $html = '<body>'.implode('', array_map(static fn (int $i): string => '<img src="/m/bild-'.$i.'.jpg" width="800" height="600">', range(1, 3000))).'</body>';

        self::assertCount(80, $this->parse($html)->images);
    }

    public function test_links_are_ranked_by_their_words_and_stay_on_the_site(): void
    {
        $html = '<body>'
            .'<a href="/kontakt">Kontakt</a>'
            .'<a href="/raeume/saal">Saal</a>'
            .'<a href="/events">Unsere Events</a>'
            .'<a href="/blog/neuigkeiten">Neuigkeiten</a>'
            .'<a href="/en/events">Events (EN)</a>'
            .'<a href="/downloads/events.pdf">Eventplan</a>'
            .'<a href="https://anderer-ort.example/events">Extern</a>'
            .'<a href="https://beispiel-venue.example/galerie/">Galerie (ohne www)</a>'
            .'<a href="/events#oben">Nochmal Events</a>'
            .'<a href="/seite/">diese Seite: Events</a>'
            .'<a href="javascript:void(0)">Events</a>'
            .'</body>';

        $paths = array_map(static fn ($link): string => $link->url->host.$link->url->path, $this->parse($html)->links);

        self::assertSame([
            'www.beispiel-venue.example/events',
            'www.beispiel-venue.example/raeume/saal',
            'beispiel-venue.example/galerie/',
            'www.beispiel-venue.example/kontakt',
        ], $paths);
    }

    public function test_headings_snippets_and_the_language_are_read_as_facts(): void
    {
        $html = '<html lang="de-CH"><body><h1>Schloss Musterburg</h1><h2>Unsere Räume</h2><p>Der Gewölbekeller fasst 60 Personen. Er misst 85 m². Und er ist schön.</p></body></html>';

        $page = $this->parse($html);

        self::assertSame(['de'], self::values($page, 'language'));
        self::assertSame(['Schloss Musterburg', 'Unsere Räume'], self::values($page, 'heading'));
        self::assertSame(['Der Gewölbekeller fasst 60 Personen.', 'Er misst 85 m².'], self::values($page, 'snippet'));
    }

    public function test_a_page_with_nothing_to_read_gives_no_facts_and_no_error(): void
    {
        $page = $this->parse('<html><body><div>.</div></body></html>');

        self::assertSame([], $page->facts->toArray());
        self::assertTrue($page->facts->isEmpty());
    }

    public function test_text_from_the_page_is_made_printable_and_storable(): void
    {
        $json = '{"@type":["Hotel","\u0000"],"name":"Seeblick\u001b[2J\u009b\u202e"}';
        $unpaired = '{"@type":"Hotel","name":"a\ud800b"}';
        $html = '<head><title>Titel '."\x1b]0;x\x07".' fertig</title><script type="application/ld+json">'.$unpaired.'</script><script type="application/ld+json">'.$json.'</script></head>';

        $facts = $this->parse($html)->facts->toArray();

        foreach ($facts as $key => $observations) {
            foreach ($observations as $observation) {
                $strings = [];
                array_walk_recursive($observation, static function (mixed $part) use (&$strings): void {
                    $strings[] = (string) $part;
                });
                foreach ($strings as $string) {
                    self::assertSame(0, preg_match('/[\p{Cc}\p{Cf}\p{Cs}]/u', $string), $key);
                    self::assertTrue(mb_check_encoding($string, 'UTF-8'), $key);
                }
            }
        }
        self::assertSame(['Hotel'], array_column($facts['type'], 'value'));
        self::assertSame('Seeblick [2J', $facts['name'][0]['value']);
        self::assertSame('Titel ]0;x fertig', $facts['name'][1]['value']);
    }

    public function test_numbers_from_json_ld_must_make_sense(): void
    {
        $nodes = [
            '{"@type":"Hotel","name":"A","geo":{"latitude":1e999,"longitude":8.5},"maximumAttendeeCapacity":1000000000}',
            '{"@type":"Hotel","name":"B","geo":{"latitude":95,"longitude":8.5},"maximumAttendeeCapacity":0}',
            '{"@type":"Hotel","name":"C","geo":{"latitude":"47.5","longitude":"8.5"},"maximumAttendeeCapacity":"250"}',
        ];
        $facts = $this->parse('<head><script type="application/ld+json">['.implode(',', $nodes).']</script></head>')->facts->toArray();

        self::assertSame([['lat' => 47.5, 'lng' => 8.5]], array_column($facts['geo'], 'value'));
        self::assertSame([250], array_column($facts['capacity'], 'value'));
    }

    public function test_an_empty_address_object_says_nothing_and_a_list_of_addresses_gives_the_first(): void
    {
        $empty = '{"@type":"Hotel","name":"A","address":{"@type":"PostalAddress","streetAddress":"","postalCode":" ","addressLocality":null}}';
        $list = '{"@type":"Hotel","name":"B","address":[{"streetAddress":"Seestrasse 9","postalCode":"8002","addressLocality":"Zürich"},{"streetAddress":"Anderswo 1"}]}';

        self::assertArrayNotHasKey('address', $this->parse('<head><script type="application/ld+json">'.$empty.'</script></head>')->facts->toArray());
        $address = $this->parse('<head><script type="application/ld+json">'.$list.'</script></head>')->facts->toArray()['address'];
        self::assertSame(['street' => 'Seestrasse 9', 'postal_code' => '8002', 'locality' => 'Zürich', 'country' => null], $address[0]['value']);
    }

    public function test_a_declared_size_beyond_any_photo_is_no_size(): void
    {
        $html = '<body><img src="/m/a.jpg" srcset="/m/a.jpg 480w, /m/b.jpg 99999999999w" width="999999" height="800"></body>';

        $image = $this->parse($html)->images[0];

        self::assertNull($image->width);
        self::assertSame(800, $image->height);
    }

    public function test_markup_nested_far_deeper_than_any_page_is_not_parsed(): void
    {
        $deep = '<html><body>'.str_repeat('<div>', 400).'x'.str_repeat('</div>', 400).'</body></html>';
        $fine = '<html><body>'.str_repeat('<div>', 200).'x'.str_repeat('</div>', 200).'</body></html>';

        self::assertSame([], $this->parse($fine)->images);
        $this->expectException(UnreadablePage::class);
        $this->parse($deep);
    }
}
