<?php

declare(strict_types=1);

namespace App\Tests\Application\Seo;

use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Zenstruck\Foundry\Test\Factories;

/**
 * SEO-5, with debug off: TwigErrorRenderer uses the custom templates only then.
 */
final class ErrorPagesTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const string POLICY = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-src https://www.google.com; frame-ancestors 'none'";

    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient(['debug' => false]);
        $this->client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_an_unknown_venue_is_the_not_found_page(): void
    {
        $crawler = $this->client->request('GET', '/hochzeitslocation/gibt-es-nicht');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('Seite nicht gefunden | Hochzeitslocation', $crawler->filter('title')->text());
        self::assertCount(1, $crawler->filter('h1'));
        self::assertSelectorTextSame('h1', 'Diese Seite gibt es nicht');
        self::assertSame('/hochzeitslocations', $crawler->filter('.error-page a')->attr('href'));
        self::assertCount(0, $crawler->filter('link[hreflang]'));
        self::assertCount(0, $crawler->filter('script[type="application/ld+json"]'));
        self::assertSame(self::POLICY, $this->client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function test_a_route_that_does_not_exist_is_the_not_found_page(): void
    {
        $this->client->request('GET', '/gibt-es-nicht');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextSame('h1', 'Diese Seite gibt es nicht');
    }

    public function test_a_removed_venue_is_the_gone_page(): void
    {
        VenueTranslationFactory::createOne([
            'venue' => VenueFactory::createOne(['status' => VenueStatus::Unpublished, 'town' => DetailVenues::town('Bern')]),
            'slug' => 'abgemeldet',
        ]);

        $crawler = $this->client->request('GET', '/hochzeitslocation/abgemeldet');

        self::assertResponseStatusCodeSame(410);
        self::assertSelectorTextSame('h1', 'Diese Location ist nicht mehr dabei');
        self::assertSame('Location nicht mehr verfügbar | Hochzeitslocation', $crawler->filter('title')->text());
        self::assertCount(0, $crawler->filter('link[hreflang]'));
    }

    public function test_a_forced_500_is_not_a_404(): void
    {
        $this->failEveryController(new \RuntimeException('forced secret'));

        $crawler = $this->client->request('GET', '/hochzeitslocations');

        self::assertResponseStatusCodeSame(500);
        self::assertSelectorTextSame('h1', 'Da ist etwas schiefgelaufen');
        self::assertSame('Fehler | Hochzeitslocation', $crawler->filter('title')->text());
        self::assertStringNotContainsString('Diese Seite gibt es nicht', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('forced secret', (string) $this->client->getResponse()->getContent());
        self::assertSame(self::POLICY, $this->client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function test_a_403_gets_the_generic_page(): void
    {
        $this->failEveryController(new AccessDeniedHttpException('forced secret'));

        $this->client->request('GET', '/hochzeitslocations');

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextSame('h1', 'Da ist etwas schiefgelaufen');
        self::assertStringNotContainsString('forced secret', (string) $this->client->getResponse()->getContent());
    }

    public function test_an_admin_404_is_the_german_page_and_stays_unlisted(): void
    {
        $this->client->request('GET', '/admin/gibt-es-nicht');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextSame('h1', 'Diese Seite gibt es nicht');
        $headers = $this->client->getResponse()->headers;
        self::assertSame(self::POLICY, $headers->get('Content-Security-Policy'));
        self::assertSame('no-store, private', $headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $headers->get('X-Robots-Tag'));
    }

    /**
     * On the main request only: the listener also fires for the error sub-request, and an
     * exception there would escape the kernel.
     */
    private function failEveryController(\Throwable $exception): void
    {
        $dispatcher = $this->client->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(KernelEvents::CONTROLLER, static function (ControllerEvent $event) use ($exception): void {
            if ($event->isMainRequest()) {
                throw $exception;
            }
        });
    }
}
