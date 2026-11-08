<?php

declare(strict_types=1);

namespace App\Tests\Application\Venues;

use App\Factory\PortalVenueAccessFactory;
use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Portal\Command\StartClaim;
use App\Portal\Command\VerifyClaim;
use App\Portal\ValueObject\ClaimOutcome;
use App\Portal\ValueObject\ClaimVerification;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Http\ThroughHttpCache;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Zenstruck\Foundry\Test\Factories;

/**
 * D18, G6: the public detail page says «Noch nicht vom Betreiber bestätigt» until somebody manages the entry, and a
 * verified claim purges the cached page, so the label goes at once.
 */
final class UnconfirmedLabelTest extends WebTestCase
{
    use Factories;
    use ThroughHttpCache;

    private const string LABEL = 'Noch nicht vom Betreiber bestätigt';
    private const string SLUG = 'remise-am-bach';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function test_an_unclaimed_entry_shows_the_label_and_a_managed_one_does_not(): void
    {
        $unclaimed = $this->venue(self::SLUG);
        $managed = $this->venue('seehaus-meilen');
        PortalVenueAccessFactory::new()->active()->create(['venue' => $managed]);
        PortalVenueAccessFactory::createOne(['venue' => $unclaimed]);

        $crawler = $this->client->request('GET', '/eventlokal/'.self::SLUG);
        self::assertResponseIsSuccessful();
        self::assertSame(self::LABEL, trim($crawler->filter('.venue-head__unconfirmed')->text()), 'a pending request confirms nothing');

        $this->client->request('GET', '/eventlokal/seehaus-meilen');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.venue-head__unconfirmed');
        self::assertStringNotContainsString(self::LABEL, (string) $this->client->getResponse()->getContent());
    }

    public function test_a_verified_claim_purges_the_cached_page_and_the_label_goes(): void
    {
        $venue = $this->venue(self::SLUG);
        $url = '/eventlokal/'.self::SLUG;
        $this->startHttpCache($this->client->getKernel());
        try {
            self::assertStringContainsString(self::LABEL, (string) $this->through(Request::create($url))->getContent());
            $cached = $this->through(Request::create($url));
            self::assertStringContainsString('fresh', (string) $cached->headers->get('X-Symfony-Cache'), 'the page is cached');
            self::assertStringContainsString(self::LABEL, (string) $cached->getContent());

            $id = EntityId::generate();
            $code = $this->dispatch(new StartClaim($id, $venue->id(), 'info@remise-am-bach.example', 'Rita', 'Bach'));
            self::assertIsString($code);
            $result = $this->dispatch(new VerifyClaim($id, $code));
            self::assertInstanceOf(ClaimVerification::class, $result);
            self::assertSame(ClaimOutcome::Granted, $result->outcome);

            $after = $this->through(Request::create($url));
            self::assertStringNotContainsString('fresh', (string) $after->headers->get('X-Symfony-Cache'));
            self::assertStringNotContainsString(self::LABEL, (string) $after->getContent());
        } finally {
            $this->stopHttpCache();
        }
    }

    private function venue(string $slug): Venue
    {
        $venue = VenueFactory::new()->afterInstantiate(static function (Venue $venue) use ($slug): void {
            $venue->changeContact(null, null, 'https://www.'.$slug.'.example/', null);
        })->create(['status' => VenueStatus::Published]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => $slug]);

        return $venue;
    }

    private function dispatch(object $command): mixed
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus->dispatch($command)->last(HandledStamp::class)?->getResult();
    }
}
