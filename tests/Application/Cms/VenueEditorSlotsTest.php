<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Tests\Fixtures\Admin\AdminExtensionProbeKernel;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Venues\DetailVenues;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\KernelInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * `_layout.html.twig` renders the registries' record tabs and panels for the venue's number (D26), so #25's
 * tabs appear without touching it. The fake module of #22 registers one of each for the type `venue`.
 */
final class VenueEditorSlotsTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use ImportsTownDataset;

    protected static function getKernelClass(): string
    {
        return AdminExtensionProbeKernel::class;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new AdminExtensionProbeKernel('test', true);
    }

    public function test_record_tabs_and_panels_render_with_the_venue_number(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();

        $crawler = $this->client->request('GET', '/admin/locations/'.$venue->number());

        self::assertResponseIsSuccessful();
        // "Übersicht" (priority 1000) stays first, then #25's Fotos to Dokumente (900–700) and #47's Statistik (500); a module's tab follows them.
        self::assertSame(['Übersicht', 'Fotos', 'Räume', 'Dokumente', 'Statistik', 'Fake Tab'], $crawler->filter('.record-header nav.tabs--record a')->each(static fn (Crawler $tab): string => $tab->text()));
        $tab = $crawler->filter('.record-header nav.tabs--record a')->last();
        self::assertSame('/admin/_probe/datensatz/'.$venue->number(), $tab->attr('href'));
        self::assertSame('Panel '.$venue->number(), $crawler->filter('aside .fake-panel')->text());
        // The panels sit between the checklist and Verlauf: CRM's (#27), the managers' (#43), then the fake one.
        self::assertSame(['venue-checklist', 'crm', 'portal-managers', null, 'venue-history'], $crawler->filter('aside.shell__aside > section, aside.shell__aside > .fake-panel')->each(
            static fn ($node): ?string => $node->attr('aria-labelledby'),
        ));
    }
}
