<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Factory\ModerationCaseFactory;
use App\Tests\Support\Admin\AdminSession;
use App\Venues\ValueObject\ModerationDecision;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * D36 in the back office: a case whose complainant the retention erased still lists and opens, with its decision,
 * and says that and when the complainant was erased instead of a name, an address or a reply link.
 */
final class ModerationErasedCaseTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    public function test_the_queue_and_the_case_page_show_an_erased_complainant(): void
    {
        $this->logInAs(AdminRole::Editor);
        $case = ModerationCaseFactory::new()
            ->decided(ModerationDecision::Keep, note: 'Die Angaben stimmten.', at: new \DateTimeImmutable('2023-03-01 10:00 Europe/Zurich'))
            ->erased(new \DateTimeImmutable('2026-03-02 03:15 Europe/Zurich'))
            ->create(['at' => new \DateTimeImmutable('2023-02-27 10:00 Europe/Zurich')]);

        $crawler = $this->client->request('GET', '/admin/meldungen');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Gelöscht', $crawler->filter('#moderation-decided tbody tr')->text());
        self::assertStringNotContainsString('Erika Beispiel', $crawler->filter('main')->text());

        $crawler = $this->client->request('GET', '/admin/meldungen/'.$case->id());

        self::assertResponseIsSuccessful();
        $main = $crawler->filter('main')->text();
        self::assertStringContainsString('Gelöscht am 02.03.2026', $main);
        self::assertStringContainsString('drei Jahre nach dem Entscheid gelöscht', $main);
        self::assertStringContainsString('Die Angaben stimmten.', $main);
        self::assertStringNotContainsString('Erika Beispiel', $main);
        self::assertStringNotContainsString('Öffnungszeiten', $main);
        self::assertCount(0, $crawler->filter('main a[href^="mailto:"]'));
        self::assertCount(0, $crawler->filter('.moderation__message'));
    }
}
