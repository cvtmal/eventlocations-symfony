<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Factory\CompanyFactory;
use App\Factory\VenueDocumentFactory;
use App\Tests\Fixtures\Admin\AdminExtensionProbeKernel;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Venues\DetailVenues;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * The panel depends on `admin_record_panels()` alone, so it renders on every tab of a venue: #25's real tabs are
 * requested one by one, and the probe's record page shows the same for a page that is no Cms page and has none of
 * the editor's variables.
 */
final class VenueCrmPanelOnRecordPagesTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new AdminExtensionProbeKernel('test', true);
    }

    public function test_it_renders_on_any_page_of_a_venue(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();
        CompanyFactory::new()->withVenues($venue)->create(['name' => 'Seeblick Events GmbH']);

        $this->client->request('GET', '/admin/_probe/datensatz/'.$venue->number());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('aside .crm-panel__name', 'Seeblick Events GmbH');
    }

    public function test_it_renders_on_every_real_tab_of_a_venue(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();
        CompanyFactory::new()->withVenues($venue)->create(['name' => 'Seeblick Events GmbH']);
        $document = VenueDocumentFactory::createOne(['venue' => $venue]);
        $base = '/admin/locations/'.$venue->number();

        foreach (['', '/fotos', '/raeume', '/dokumente', '/dokumente/'.$document->id()] as $tab) {
            $this->client->request('GET', $base.$tab);

            self::assertResponseIsSuccessful($base.$tab);
            self::assertSelectorTextSame('aside .crm-panel__name', 'Seeblick Events GmbH', $base.$tab);
        }
    }

    public function test_it_offers_the_button_on_any_page_of_a_venue(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();

        $crawler = $this->client->request('GET', '/admin/_probe/datensatz/'.$venue->number());

        self::assertSame('/admin/crm/locations/'.$venue->id().'/firma', $crawler->filter('aside .crm-panel__create')->attr('action'));
    }

    public function test_a_number_no_venue_has_renders_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('GET', '/admin/_probe/datensatz/999999999');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#crm');
    }
}
