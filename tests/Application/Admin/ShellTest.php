<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Admin\ValueObject\AdminRole;
use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

final class ShellTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    public function test_an_admin_sees_the_sections_in_order_with_the_active_item(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSame(['CMS', 'CRM', 'Einstellungen'], $crawler->filter('.sidebar__heading')->each(static fn (Crawler $heading): string => $heading->text()));
        self::assertSame(['Übersicht', 'Locations', 'Landingpages', 'Textbausteine', 'Firmen', 'Kontakte', 'Aufgaben', 'Benutzer', 'Audit-Log'], $crawler->filter('.sidebar__label')->each(static fn (Crawler $label): string => $label->text()));
        self::assertSame('Übersicht', $crawler->filter('.sidebar__item[aria-current="page"]')->text());
    }

    public function test_an_editor_sees_no_settings(): void
    {
        $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', '/admin');

        self::assertSame(['Übersicht', 'Locations', 'Landingpages', 'Textbausteine', 'Firmen', 'Kontakte', 'Aufgaben'], $crawler->filter('.sidebar__label')->each(static fn (Crawler $label): string => $label->text()));
        self::assertSame(['CMS', 'CRM'], $crawler->filter('.sidebar__heading')->each(static fn (Crawler $heading): string => $heading->text()));
    }

    public function test_the_user_block_shows_who_is_logged_in_and_a_csrf_logout(): void
    {
        $this->logInAs(displayName: 'Andrea Keller');

        $crawler = $this->client->request('GET', '/admin');

        self::assertSelectorTextContains('.sidebar__name', 'Andrea Keller');
        self::assertSelectorTextContains('.sidebar__role', 'Admin');
        self::assertSelectorTextContains('.sidebar__avatar', 'AK');
        self::assertCount(1, $crawler->filter('form.sidebar__logout-form[method="post"] input[name="_csrf_token"]'));
        self::assertSelectorExists('button.sidebar__logout[aria-label="Abmelden"]');
    }

    public function test_the_page_has_a_skip_link_breadcrumbs_and_the_api_token(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin');

        self::assertSelectorExists('a.skip-link[href="#main"]');
        self::assertSelectorExists('main#main');
        self::assertSame('Übersicht', $crawler->filter('.breadcrumbs [aria-current="page"]')->text());
        self::assertNotEmpty((string) $crawler->filter('meta[name="csrf-token"]')->attr('content'));
        self::assertSelectorExists('script[type="module"][src*="js/admin/toast"]');
    }

    public function test_a_flash_is_a_toast(): void
    {
        $this->logInAs();
        $other = $this->createStaff(AdminRole::Editor, displayName: 'Zoe Zweit');
        $this->client->request('GET', '/admin/benutzer/'.$other['user']->id());
        $this->client->submitForm('Speichern', ['change_admin_role[role]' => 'admin']);
        $crawler = $this->client->followRedirect();

        self::assertSame('Die Rolle ist geändert.', $crawler->filter('.toast-region[role="status"] .toast--success .toast__message')->text());
        self::assertCount(1, $crawler->filter('.toast [data-toast-dismiss]'));
    }
}
