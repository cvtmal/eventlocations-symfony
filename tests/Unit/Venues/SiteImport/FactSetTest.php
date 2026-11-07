<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues\SiteImport;

use App\Venues\SiteImport\FactSet;
use PHPUnit\Framework\TestCase;

final class FactSetTest extends TestCase
{
    public function test_observations_come_back_most_confident_first_with_their_source(): void
    {
        $facts = new FactSet();
        $facts->add('name', 'Titel der Seite', 0.4, 'https://a.example/', 'title');
        $facts->add('name', 'Schloss Musterburg', 0.9, 'https://a.example/', 'json_ld');

        self::assertSame([
            'name' => [
                ['value' => 'Schloss Musterburg', 'confidence' => 0.9, 'source_url' => 'https://a.example/', 'via' => 'json_ld'],
                ['value' => 'Titel der Seite', 'confidence' => 0.4, 'source_url' => 'https://a.example/', 'via' => 'title'],
            ],
        ], $facts->toArray());
    }

    public function test_an_equal_value_is_kept_once_with_the_higher_confidence(): void
    {
        $facts = new FactSet();
        $facts->add('phone', '+41 44 555 01 23', 0.5, 'https://a.example/', 'text');
        $facts->add('phone', '+41 44 555 01 23', 0.8, 'https://a.example/kontakt', 'link');
        $facts->add('phone', '+41 44 555 01 23', 0.3, 'https://a.example/x', 'text');

        $phone = $facts->toArray()['phone'];
        self::assertCount(1, $phone);
        self::assertSame(0.8, $phone[0]['confidence']);
        self::assertSame('https://a.example/kontakt', $phone[0]['source_url']);
    }

    public function test_text_is_collapsed_trimmed_cut_and_empty_text_is_dropped(): void
    {
        $facts = new FactSet();
        $facts->add('name', "  Schloss \n  Musterburg  ", 0.5, 'https://a.example/', 'html');
        $facts->add('name', '   ', 0.9, 'https://a.example/', 'html');
        $facts->add('description', str_repeat('ä', 900), 0.5, 'https://a.example/', 'meta');

        $all = $facts->toArray();
        self::assertSame('Schloss Musterburg', $all['name'][0]['value']);
        self::assertCount(1, $all['name']);
        self::assertIsString($all['description'][0]['value']);
        self::assertSame(600, mb_strlen($all['description'][0]['value']));
    }

    public function test_a_key_keeps_only_its_limit_of_observations_and_a_list_keeps_page_order(): void
    {
        $facts = new FactSet();
        foreach (range(1, 6) as $i) {
            $facts->add('email', "a{$i}@a.example", 0.5, 'https://a.example/', 'text');
        }
        foreach (range(1, 40) as $i) {
            $facts->add('heading', "Titel {$i}", 0.5, 'https://a.example/', 'html');
        }

        $all = $facts->toArray();
        self::assertCount(3, $all['email']);
        self::assertCount(30, $all['heading']);
        self::assertSame('Titel 1', $all['heading'][0]['value']);
        self::assertSame('Titel 30', $all['heading'][29]['value']);
    }

    public function test_structured_values_and_merging(): void
    {
        $address = ['street' => 'Musterweg 12', 'postal_code' => '8000', 'locality' => 'Zürich', 'country' => null];
        $first = new FactSet();
        $first->add('address', $address, 0.5, 'https://a.example/', 'text');
        $second = new FactSet();
        $second->add('address', $address, 0.9, 'https://a.example/impressum', 'json_ld');
        $second->add('capacity', 120, 0.8, 'https://a.example/raeume', 'json_ld');

        $first->merge($second);

        $all = $first->toArray();
        self::assertSame(['address', 'capacity'], array_keys($all));
        self::assertCount(1, $all['address']);
        self::assertSame(0.9, $all['address'][0]['confidence']);
        self::assertSame(120, $all['capacity'][0]['value']);
        self::assertFalse($first->isEmpty());
        self::assertTrue((new FactSet())->isEmpty());
    }
}
