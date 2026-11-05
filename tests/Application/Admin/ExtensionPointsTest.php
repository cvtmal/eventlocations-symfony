<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Admin\Extension\TimelineActivityType;
use App\Admin\Extension\TimelineActivityTypeRegistry;
use App\Admin\ValueObject\AdminRole;
use App\Tests\Fixtures\Admin\AdminExtensionProbeKernel;
use App\Tests\Support\Admin\AdminSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Zenstruck\Foundry\Test\Factories;

/**
 * ADM-5: a module that only implements the interfaces appears in the shell, and the JSON convention holds.
 */
final class ExtensionPointsTest extends WebTestCase
{
    use AdminSession;
    use ChecksAdminRoutes;
    use Factories;

    private const string RECORD = '/admin/_probe/datensatz/0199f0d0-0000-7000-8000-000000000001';

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

    public function test_navigation_items_render_ordered_by_priority(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin');

        self::assertSame(
            ['Übersicht', 'Statistik', 'Einträge', 'Prüfung', 'Landingpages', 'Textbausteine', 'Fake Zweiter', 'Fake Erster', 'Locations', 'Kontakte', 'Deals', 'Anfragen', 'Aufgaben', 'Fake Nur Admin', 'Benutzer', 'Konten', 'Audit-Log', 'E-Mail-Vorlagen'],
            $crawler->filter('.sidebar__label')->each(static fn (Crawler $label): string => $label->text()),
        );
        self::assertSame(['CMS', 'CRM', 'Einstellungen'], $crawler->filter('.sidebar__heading')->each(static fn (Crawler $heading): string => $heading->text()));
        self::assertSame('7', $crawler->filter('.sidebar__badge')->text());
    }

    public function test_an_item_for_admins_is_hidden_from_an_editor(): void
    {
        $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', '/admin');

        self::assertSame(['Übersicht', 'Statistik', 'Einträge', 'Prüfung', 'Landingpages', 'Textbausteine', 'Fake Zweiter', 'Fake Erster', 'Locations', 'Kontakte', 'Deals', 'Anfragen', 'Aufgaben'], $crawler->filter('.sidebar__label')->each(static fn (Crawler $label): string => $label->text()));
    }

    public function test_a_widget_renders_in_its_card(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin');

        self::assertSelectorNotExists('.empty');
        // #47's Statistik widget is wide too; the fake one is the wide card holding its markup.
        $widget = $crawler->filter('section.widget--wide')->reduce(static fn (Crawler $card): bool => $card->filter('.fake-widget')->count() > 0);
        self::assertSame('Fake Widget', $widget->filter('h2')->text());
        self::assertSame('Inhalt 42', $widget->filter('.fake-widget')->text());
        self::assertSame($widget->filter('h2')->attr('id'), $widget->attr('aria-labelledby'));
    }

    public function test_record_tabs_and_panels_render_for_their_type(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', self::RECORD);

        self::assertResponseIsSuccessful();
        // The CMS's own venue tabs (#24's Übersicht, #25's Fotos to Dokumente, #47's Statistik) come first; the fake one follows them.
        self::assertSame(['Übersicht', 'Fotos', 'Räume', 'Dokumente', 'Statistik', 'Fake Tab'], $crawler->filter('.fake-tabs a')->each(static fn (Crawler $tab): string => $tab->text()));
        self::assertSame(self::RECORD, $crawler->filter('.fake-tabs a')->last()->attr('href'));
        self::assertSelectorNotExists('.fake-wrong-type');
        self::assertSame('Panel 0199f0d0-0000-7000-8000-000000000001', $crawler->filter('aside .fake-panel')->text());
    }

    public function test_a_timeline_type_is_collected_and_filtered_by_role(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(TimelineActivityTypeRegistry::class);
        self::assertInstanceOf(TimelineActivityTypeRegistry::class, $registry);
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);

        // The fake type outranks the CRM ones, needs ROLE_ADMIN and cannot be written by hand.
        $tokens->setToken(self::tokenFor('ROLE_ADMIN'));
        self::assertSame(['fake_activity', 'note', 'email_logged', 'email_sent', 'call', 'meeting', 'inquiry', 'deal_stage_changed'], self::codes($registry->visible()));
        self::assertSame(['note', 'email_logged', 'call', 'meeting'], self::codes($registry->composable()));

        $tokens->setToken(self::tokenFor('ROLE_EDITOR'));
        self::assertSame(['note', 'email_logged', 'email_sent', 'call', 'meeting', 'inquiry', 'deal_stage_changed'], self::codes($registry->visible()));
    }

    /**
     * @param list<TimelineActivityType> $types
     *
     * @return list<string>
     */
    private static function codes(array $types): array
    {
        return array_map(static fn (TimelineActivityType $type): string => $type->code(), $types);
    }

    private static function tokenFor(string $role): UsernamePasswordToken
    {
        return new UsernamePasswordToken(new InMemoryUser('probe', null, [$role]), 'admin', [$role]);
    }

    public function test_the_active_item_follows_the_route(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', self::RECORD);

        self::assertSame('Fake Erster', $crawler->filter('.sidebar__item[aria-current="page"] .sidebar__label')->text());
    }

    public function test_json_without_a_token_is_refused(): void
    {
        $this->logInAs();
        $this->token();

        $this->post('/admin/api/probe/echo', ['name' => 'x'], null);
        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $this->post('/admin/api/probe/echo', ['name' => 'x'], 'wrong');
        self::assertResponseStatusCodeSame(403);
    }

    public function test_json_with_the_token_succeeds(): void
    {
        $this->logInAs();
        $token = $this->token();

        $this->post('/admin/api/probe/echo', ['name' => 'Anna'], $token);

        self::assertResponseIsSuccessful();
        self::assertSame(['name' => 'Anna'], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function test_invalid_json_input_is_a_422_with_field_errors(): void
    {
        $this->logInAs();
        $token = $this->token();

        $this->post('/admin/api/probe/echo', ['name' => ''], $token);

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['errors']['[name]'] ?? null);
    }

    public function test_an_anonymous_json_request_gets_a_json_401(): void
    {
        $this->post('/admin/api/probe/echo', ['name' => 'x'], null);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function test_an_unenrolled_user_gets_a_json_403(): void
    {
        $staff = $this->createStaff(enrolled: false);
        $this->logIn($staff['user'], null);

        $this->post('/admin/api/probe/echo', ['name' => 'x'], null);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function encodedJsonUrls(): iterable
    {
        foreach (self::encodedSpellings('/admin/api/probe/echo') as $spelling => $url) {
            yield $spelling => [$url];
        }
    }

    #[DataProvider('encodedJsonUrls')]
    public function test_an_encoded_url_is_still_json_for_an_anonymous_request(string $url): void
    {
        $this->post($url, ['name' => 'x'], null);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    #[DataProvider('encodedJsonUrls')]
    public function test_an_encoded_url_is_still_json_for_an_unenrolled_user(string $url): void
    {
        $staff = $this->createStaff(enrolled: false);
        $this->logIn($staff['user'], null);

        $this->post($url, ['name' => 'x'], null);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    #[DataProvider('encodedJsonUrls')]
    public function test_an_encoded_url_is_still_json_for_a_bad_token(string $url): void
    {
        $this->logInAs();
        $this->token();

        $this->post($url, ['name' => 'x'], 'wrong');

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function test_a_json_route_for_admins_refuses_an_editor_in_json(): void
    {
        $this->logInAs(AdminRole::Editor);
        $token = $this->token();

        foreach (['/admin/api/probe/nur-admin', ...array_values(self::encodedSpellings('/admin/api/probe/nur-admin'))] as $url) {
            $this->post($url, ['name' => 'x'], $token);

            self::assertResponseStatusCodeSame(403, $url);
            self::assertResponseHeaderSame('Content-Type', 'application/json', $url);
        }
    }

    public function test_every_json_controller_checks_the_csrf_header(): void
    {
        $checked = 0;
        foreach ($this->adminRoutes() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/admin/api/')) {
                continue;
            }

            $controller = $route->getDefault('_controller');
            self::assertIsString($controller, $name);
            $class = explode('::', $controller)[0];
            self::assertTrue(class_exists($class), $name);
            self::assertNotEmpty((new \ReflectionClass($class))->getAttributes(IsCsrfTokenValid::class), $name.' carries #[IsCsrfTokenValid].');
            ++$checked;
        }

        self::assertGreaterThan(0, $checked);
    }

    /**
     * AdminRoutesAreProtectedTest walks the app's routes; here only what the fake module adds, which is
     * what a later cycle's routes will look like.
     */
    private function isWalked(string $routeName): bool
    {
        return str_contains($routeName, 'probe');
    }

    private function token(): string
    {
        return (string) $this->client->request('GET', '/admin')->filter('meta[name="csrf-token"]')->attr('content');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $url, array $body, ?string $token): void
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_X_CSRF_TOKEN'] = $token;
        }

        $this->client->request('POST', $url, server: $server, content: json_encode($body, \JSON_THROW_ON_ERROR));
    }
}
