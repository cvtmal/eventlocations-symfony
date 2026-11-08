<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Venues\DetailVenues;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * P1: a textarea submits CRLF, and the public page splits a description on "\n\n"; the admin form folds it to
 * LF, so a saved description keeps its paragraphs.
 */
final class VenueTextsCardTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use ImportsTownDataset;

    public function test_a_crlf_description_is_stored_with_lf_and_keeps_its_paragraphs(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        $form = $this->client->request('GET', '/admin/locations/'.$venue->number())->filter('form[name="venue_texts"]')->form();
        $form['venue_texts[texts][de][description]'] = "Erster Absatz im Garten.\r\n\r\nZweiter Absatz am Teich.";

        $this->client->submit($form);

        self::assertResponseRedirects('/admin/locations/'.$venue->number().'#texte');
        self::assertSame("Erster Absatz im Garten.\n\nZweiter Absatz am Teich.", $this->connection->fetchOne("SELECT description FROM venue_translations WHERE venue_id = ? AND locale = 'de'", [$venue->id()]));
        $crawler = $this->client->request('GET', '/eventlokal/'.DetailVenues::MINIMAL_SLUG);
        self::assertSame(
            ['Erster Absatz im Garten.', 'Zweiter Absatz am Teich.'],
            $crawler->filter('p')->reduce(static fn ($p): bool => str_contains($p->text(), 'Absatz'))->each(static fn ($p): string => $p->text()),
        );
    }
}
