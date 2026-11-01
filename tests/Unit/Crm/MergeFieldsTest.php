<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\ValueObject\MergeFields;
use PHPUnit\Framework\TestCase;

final class MergeFieldsTest extends TestCase
{
    public function test_all_three_fields_are_filled(): void
    {
        $fields = new MergeFields('Mia', 'Muster AG', 'Schloss Seeblick');

        self::assertSame(
            'Liebe Mia, Muster AG führt Schloss Seeblick.',
            $fields->apply('Liebe {contact.first_name}, {company.name} führt {venue.name}.'),
        );
    }

    public function test_the_tokens_are_the_fixed_list(): void
    {
        self::assertSame(['{contact.first_name}', '{company.name}', '{venue.name}'], MergeFields::TOKENS);
    }

    public function test_a_null_value_is_empty(): void
    {
        $fields = new MergeFields(null, null, null);

        self::assertSame('Liebe , zu  und .', $fields->apply('Liebe {contact.first_name}, zu {company.name} und {venue.name}.'));
    }

    public function test_an_unknown_token_stays_literal(): void
    {
        $fields = new MergeFields('Mia', 'Muster AG', 'Schloss Seeblick');

        self::assertSame('{contact.last_name} und {venue.city}', $fields->apply('{contact.last_name} und {venue.city}'));
    }

    public function test_twig_stays_literal(): void
    {
        $fields = new MergeFields('Mia', 'Muster AG', 'Schloss Seeblick');

        self::assertSame('{{ 7*7 }} {% if true %}', $fields->apply('{{ 7*7 }} {% if true %}'));
    }

    public function test_a_value_holding_a_token_is_not_expanded_again(): void
    {
        $fields = new MergeFields('{company.name}', 'Muster AG', null);

        self::assertSame('{company.name}, Muster AG', $fields->apply('{contact.first_name}, {company.name}'));
    }

    public function test_a_token_used_twice_is_filled_twice(): void
    {
        $fields = new MergeFields('Mia', null, null);

        self::assertSame('Mia und Mia', $fields->apply('{contact.first_name} und {contact.first_name}'));
    }
}
