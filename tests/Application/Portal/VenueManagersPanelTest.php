<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Admin\ValueObject\AdminRole;
use App\Factory\PortalUserFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\VenueFactory;
use App\Portal\Command\GrantVenueAccess;
use App\Portal\Command\RevokeVenueAccess;
use App\Portal\Entity\PortalUser;
use App\Portal\Extension\VenueManagersPanel;
use App\Shared\Uid\EntityId;
use App\Tests\Application\Crm\ForgedPosts;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;
use Zenstruck\Foundry\Test\Factories;

final class VenueManagersPanelTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use ForgedPosts;

    private const string SAME_SITE_TOKEN = 'csrf-token';
    private const array SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];

    public function test_it_lists_active_managers_only(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();
        $this->manager($venue, ['firstName' => 'Zora', 'lastName' => 'Zürcher', 'email' => 'zora@example.ch']);
        $this->manager($venue, ['firstName' => 'Anna', 'lastName' => 'Ammann', 'email' => 'anna@example.ch']);
        PortalVenueAccessFactory::createOne(['user' => PortalUserFactory::createOne(['email' => 'pending@example.ch']), 'venue' => $venue]);
        $this->manager(VenueFactory::createOne(), ['email' => 'other@example.ch']);

        $panel = $this->panel($venue);

        self::assertSame('Location-Manager', $panel->filter('h2')->text());
        self::assertSame(['anna@example.ch', 'zora@example.ch'], $panel->filter('li .muted')->each(static fn (Crawler $email): string => $email->text()));
        self::assertStringContainsString('Anna Ammann', $panel->filter('li')->first()->text());
        self::assertStringContainsString('Zora Zürcher', $panel->filter('li')->last()->text());
    }

    public function test_a_location_without_a_manager_says_so(): void
    {
        $this->logInAs(AdminRole::Editor);

        $panel = $this->panel(DetailVenues::withoutPlace());

        self::assertSame('Diese Location hat noch keine Manager.', $panel->filter('p.muted')->text());
        self::assertCount(0, $panel->filter('li'));
    }

    public function test_it_adds_an_existing_account_by_email(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();
        $manager = PortalUserFactory::createOne(['firstName' => 'Petra', 'lastName' => 'Keller', 'email' => 'petra@example.ch']);

        $form = $this->panel($venue)->filter('form[action$="/manager"]')->form(['portal_email[email]' => 'Petra@Example.ch']);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/locations/'.$venue->number().'#portal-managers', 303);
        self::assertSame('active', $this->accessStatus($manager, $venue));
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Manager hinzugefügt.', $crawler->filter('.toast--success')->text());
        self::assertStringContainsString('Petra Keller', $crawler->filter('section[aria-labelledby="portal-managers"] li')->text());
    }

    public function test_adding_is_audited_and_sends_no_mail(): void
    {
        $staff = $this->logInAs();
        $venue = VenueFactory::createOne();
        $manager = PortalUserFactory::createOne();

        $this->add($venue, $manager->email());

        self::assertEmailCount(0);
        $audit = $this->connection->fetchAllAssociative('SELECT actor_id, subject_type, subject_id, changes FROM admin_audit_log WHERE command = ?', [GrantVenueAccess::class]);
        self::assertCount(1, $audit);
        self::assertSame($staff['user']->id(), $audit[0]['actor_id']);
        self::assertSame('portal_user', $audit[0]['subject_type']);
        self::assertSame($manager->id(), $audit[0]['subject_id']);
        self::assertStringContainsString(GrantVenueAccess::VIA_PANEL, (string) $audit[0]['changes']);
    }

    public function test_adding_reaches_the_crm(): void
    {
        $this->logInAs();
        $manager = PortalUserFactory::createOne();

        $this->add(VenueFactory::createOne(), $manager->email());

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_contacts WHERE email = ?', [$manager->email()]));
    }

    public function test_adding_a_pending_account_makes_it_active_and_it_leaves_the_queue(): void
    {
        $this->logInAs();
        $venue = VenueFactory::createOne();
        $manager = PortalUserFactory::createOne();
        PortalVenueAccessFactory::createOne(['user' => $manager, 'venue' => $venue]);

        $this->add($venue, $manager->email());

        self::assertSame('active', $this->accessStatus($manager, $venue));
        $crawler = $this->client->request('GET', '/admin/pruefung');
        self::assertSame('Keine offenen Zugriffsanfragen', $crawler->filter('.empty__title')->text());
    }

    public function test_adding_an_unknown_address_flashes(): void
    {
        $this->logInAs();
        $venue = DetailVenues::withoutPlace();

        $this->add($venue, 'nobody@example.ch');

        self::assertResponseRedirects('/admin/locations/'.$venue->number().'#portal-managers', 303);
        self::assertSame(0, $this->auditCount(GrantVenueAccess::class));
        self::assertStringContainsString('Zu dieser E-Mail-Adresse gibt es kein Konto.', $this->client->followRedirect()->filter('.toast--error')->text());
    }

    /**
     * @return iterable<string, array{string|list<string>}>
     */
    public static function invalidAddresses(): iterable
    {
        yield 'no address' => ['not-an-address'];
        yield 'empty' => [''];
        yield 'a NUL' => ["a\0b@example.ch"];
        yield 'invalid UTF-8' => ["a\xFFb@example.ch"];
        yield 'an array' => [['a@example.ch']];
    }

    /**
     * @param string|list<string> $address
     */
    #[DataProvider('invalidAddresses')]
    public function test_adding_an_invalid_address_flashes(string|array $address): void
    {
        $this->logInAs();
        $venue = DetailVenues::withoutPlace();

        $this->add($venue, $address);

        self::assertResponseRedirects('/admin/locations/'.$venue->number().'#portal-managers', 303);
        self::assertSame(0, $this->auditCount(GrantVenueAccess::class));
        self::assertStringContainsString('Bitte eine gültige E-Mail-Adresse eingeben.', $this->client->followRedirect()->filter('.toast--error')->text());
    }

    public function test_adding_an_active_manager_writes_no_audit_row(): void
    {
        $this->logInAs();
        $venue = DetailVenues::withoutPlace();
        $manager = $this->manager($venue);

        $this->add($venue, $manager->email());

        self::assertSame(0, $this->auditCount(GrantVenueAccess::class));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_contacts'));
        self::assertStringContainsString('bereits Manager', $this->client->followRedirect()->filter('.toast--success')->text());
    }

    public function test_it_removes_a_manager_without_a_mail(): void
    {
        $staff = $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();
        $manager = $this->manager($venue);
        $name = $manager->firstName().' '.$manager->lastName();

        $button = $this->panel($venue)->filter('li form button');
        self::assertSame($name.' als Manager entfernen', $button->attr('aria-label'));
        $this->client->submit($button->form());

        self::assertResponseRedirects('/admin/locations/'.$venue->number().'#portal-managers', 303);
        self::assertNull($this->accessStatus($manager, $venue));
        self::assertEmailCount(0);
        $audit = $this->connection->fetchAllAssociative('SELECT actor_id, subject_type, subject_id, changes FROM admin_audit_log WHERE command = ?', [RevokeVenueAccess::class]);
        self::assertCount(1, $audit);
        self::assertSame($staff['user']->id(), $audit[0]['actor_id']);
        self::assertSame('portal_user', $audit[0]['subject_type']);
        self::assertSame($manager->id(), $audit[0]['subject_id']);
        self::assertStringContainsString(GrantVenueAccess::VIA_PANEL, (string) $audit[0]['changes']);
        self::assertStringContainsString('Manager entfernt.', $this->client->followRedirect()->filter('.toast--success')->text());
    }

    public function test_removing_someone_who_is_no_manager_changes_nothing(): void
    {
        $this->logInAs();
        $venue = DetailVenues::withoutPlace();
        $pending = PortalUserFactory::createOne();
        PortalVenueAccessFactory::createOne(['user' => $pending, 'venue' => $venue]);

        $this->remove($venue, $pending->id());

        self::assertSame('pending', $this->accessStatus($pending, $venue));
        self::assertSame(0, $this->auditCount(RevokeVenueAccess::class));
        self::assertStringContainsString('nicht mehr Manager', $this->client->followRedirect()->filter('.toast--error')->text());
    }

    /**
     * @param array<string, string> $token
     * @param array<string, string> $server
     */
    #[DataProvider('forgedPosts')]
    public function test_a_bad_token_changes_nothing(array $token, array $server): void
    {
        $this->logInAs();
        $venue = VenueFactory::createOne();
        $manager = $this->manager($venue);
        $account = PortalUserFactory::createOne();
        $path = '/admin/pruefung/locations/'.$venue->number().'/manager';

        $this->client->request('POST', $path, ['portal_email' => ['email' => $account->email()] + $token], server: $server);
        self::assertResponseRedirects('/admin/locations/'.$venue->number().'#portal-managers', 303);

        $this->client->request('POST', $path.'/'.$manager->id().'/entfernen', $token, server: $server);
        self::assertResponseRedirects('/admin/locations/'.$venue->number().'#portal-managers', 303);

        self::assertNull($this->accessStatus($account, $venue));
        self::assertSame('active', $this->accessStatus($manager, $venue));
        self::assertSame(0, $this->auditCount(GrantVenueAccess::class));
        self::assertSame(0, $this->auditCount(RevokeVenueAccess::class));
    }

    public function test_an_unknown_location_is_a_404(): void
    {
        $this->logInAs();
        $account = PortalUserFactory::createOne();

        $this->client->request('POST', '/admin/pruefung/locations/999999999/manager', ['portal_email' => ['email' => $account->email(), '_token' => self::SAME_SITE_TOKEN]], server: self::SAME_ORIGIN);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/admin/pruefung/locations/999999999/manager/'.$account->id().'/entfernen', ['_token' => self::SAME_SITE_TOKEN], server: self::SAME_ORIGIN);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_non_number_renders_nothing(): void
    {
        $panel = self::getContainer()->get(VenueManagersPanel::class);
        self::assertInstanceOf(VenueManagersPanel::class, $panel);
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $context = $panel->context(EntityId::generate());

        self::assertSame([], $context);
        self::assertSame('', trim($twig->render($panel->template(), $context)));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function manager(Venue $venue, array $attributes = []): PortalUser
    {
        $manager = PortalUserFactory::createOne($attributes);
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $venue]);

        return $manager;
    }

    private function panel(Venue $venue): Crawler
    {
        $crawler = $this->client->request('GET', '/admin/locations/'.$venue->number());
        self::assertResponseIsSuccessful();

        return $crawler->filter('aside section[aria-labelledby="portal-managers"]');
    }

    /**
     * @param string|list<string> $email
     */
    private function add(Venue $venue, string|array $email): void
    {
        $this->client->request('POST', '/admin/pruefung/locations/'.$venue->number().'/manager', ['portal_email' => ['email' => $email, '_token' => self::SAME_SITE_TOKEN]], server: self::SAME_ORIGIN);
    }

    private function remove(Venue $venue, string $userId): void
    {
        $this->client->request('POST', '/admin/pruefung/locations/'.$venue->number().'/manager/'.$userId.'/entfernen', ['_token' => self::SAME_SITE_TOKEN], server: self::SAME_ORIGIN);
    }

    private function accessStatus(PortalUser $manager, Venue $venue): ?string
    {
        $status = $this->connection->fetchOne('SELECT status FROM portal_venue_access WHERE portal_user_id = ? AND venue_id = ?', [$manager->id(), $venue->id()]);

        return false === $status ? null : (string) $status;
    }

    private function auditCount(string $command): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM admin_audit_log WHERE command = ?', [$command]);
    }
}
