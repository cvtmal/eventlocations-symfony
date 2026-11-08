<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared;

use App\Tests\Support\Venues\DetailVenues;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Yaml\Yaml;
use Zenstruck\Foundry\Test\Factories;

/**
 * F7b: the public copy speaks of events, not weddings (E1, D7). "Hochzeit" is one event type, so the bare label passes;
 * every other wedding word fails, in the public catalogues and in the rendered public pages. The competitor's domain
 * never appears in public copy (D11).
 *
 * Two namespaces are still other cycles' copy and are skipped in the catalogues: `landing.*` of `search` and `seo` until
 * landing-event (F8) merges, and the inquiry's Occasion list (`inquiries` `occasion.*`, the styleguide's copy of it) until
 * inquiry-event (F9) merges. Whichever of F7b and F8 (F9) rebases second removes its skip. In rendered pages only the
 * Occasion labels pass, and only on the detail page (its inquiry form): no page here renders a landing heading.
 * Public Twig and `assets/js` must carry no copy of their own, so they get the same check without exceptions.
 */
final class NoWeddingCopyTest extends WebTestCase
{
    use Factories;

    private const array STAFF = ['admin', 'cms', 'crm'];
    private const string WEDDING = '/[\p{L}-]*(?:hochzeit|trauung|braut|heirat|traumlocation|grossen tag)[\p{L}-]*/iu';
    private const string EVENT_TYPE_LABEL = 'Hochzeit';
    private const string COMPETITOR = '/eventlokale\.ch/iu';
    /** domain => key prefixes skipped until the owning cycle merges (see the class comment) */
    private const array SKIPPED = [
        'search' => ['landing.'], // until landing-event (F8) merges
        'seo' => ['landing.'], // until landing-event (F8) merges
        'inquiries' => ['occasion.'], // until inquiry-event (F9) merges
        'styleguide' => ['styleguide.text_input.occasion_'], // the styleguide's copy of the Occasion list, until inquiry-event (F9) merges
    ];
    /** Skipped keys whose words may render, until inquiry-event (F9) merges: the detail page's Anlass select. */
    private const array OCCASION_KEYS = ['inquiries' => 'occasion.'];

    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
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

    public function test_public_catalogues_have_no_wedding_wording_and_no_competitor(): void
    {
        $found = [];
        foreach (self::catalogues() as $domain => $messages) {
            foreach ($messages as $key => $value) {
                if (self::skipped($domain, $key)) {
                    continue;
                }
                if (self::EVENT_TYPE_LABEL !== $value && 1 === preg_match(self::WEDDING, $value)) {
                    $found[] = $domain.': '.$key;
                }
                if (1 === preg_match(self::COMPETITOR, $value)) {
                    $found[] = $domain.': '.$key.' (competitor)';
                }
            }
        }

        self::assertSame([], $found);
    }

    public function test_the_guard_fires_on_a_wedding_sentence_and_lets_the_label_pass(): void
    {
        self::assertSame(1, preg_match(self::WEDDING, 'Plane deine Hochzeit'));
        self::assertSame(1, preg_match(self::WEDDING, 'Hochzeitslocations in der Schweiz'));
        self::assertSame(1, preg_match(self::WEDDING, 'eine freie Trauung'));
        self::assertSame(1, preg_match(self::COMPETITOR, 'wie auf eventlokale.ch'));
        self::assertSame(0, preg_match(self::WEDDING, 'Eventlokale in der Schweiz'));
        self::assertSame(0, preg_match(self::COMPETITOR, '/eventlokale/zuerich'));
        self::assertTrue(self::skipped('search', 'landing.canton.heading'));
        self::assertFalse(self::skipped('search', 'listing.heading'));
    }

    public function test_rendered_public_pages_have_no_wedding_wording_and_no_competitor(): void
    {
        DetailVenues::minimal();
        $detail = '/eventlokal/'.DetailVenues::MINIMAL_SLUG;

        $found = [];
        foreach (['/', '/eventlokale', $detail, '/merkliste', '/eventlokal-in-meiner-naehe', '/impressum', '/datenschutz', '/llms.txt'] as $path) {
            $allowed = $path === $detail ? [self::EVENT_TYPE_LABEL, ...self::occasionWords()] : [self::EVENT_TYPE_LABEL];
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
            $body = (string) $this->client->getResponse()->getContent();

            preg_match_all(self::WEDDING, $body, $matches);
            foreach (array_diff(array_unique($matches[0]), $allowed) as $word) {
                $found[] = $path.': '.$word;
            }
            if (1 === preg_match(self::COMPETITOR, $body)) {
                $found[] = $path.': competitor';
            }
        }

        self::assertSame([], $found);
    }

    public function test_public_templates_and_scripts_carry_no_wedding_wording(): void
    {
        $root = \dirname(__DIR__, 3);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/templates', \FilesystemIterator::SKIP_DOTS));
        $found = [];
        foreach ([...iterator_to_array($files, false), ...(glob($root.'/assets/js/*.js') ?: [])] as $file) {
            $path = substr((string) $file, \strlen($root) + 1);
            if (str_starts_with($path, 'templates/admin/')) {
                continue;
            }
            $content = (string) file_get_contents((string) $file);
            if (1 === preg_match(self::WEDDING, $content) || 1 === preg_match(self::COMPETITOR, $content)) {
                $found[] = $path;
            }
        }

        self::assertGreaterThan(50, \count(iterator_to_array($files, false)));
        self::assertSame([], $found);
    }

    /**
     * The words of the Occasion labels, which the detail page's inquiry form renders until inquiry-event (F9) merges.
     *
     * @return list<string>
     */
    private static function occasionWords(): array
    {
        $words = [];
        foreach (self::OCCASION_KEYS as $domain => $prefix) {
            foreach (self::catalogues()[$domain] as $key => $value) {
                if (str_starts_with($key, $prefix) && preg_match_all(self::WEDDING, $value, $matches) > 0) {
                    array_push($words, ...$matches[0]);
                }
            }
        }

        return array_values(array_unique($words));
    }

    private static function skipped(string $domain, string $key): bool
    {
        foreach (self::SKIPPED[$domain] ?? [] as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every public catalogue, as PublicCopyAddressTest finds them: all but the staff ones.
     *
     * @return array<string, array<string, string>> domain => flat key => message
     */
    private static function catalogues(): array
    {
        $catalogues = [];
        foreach (glob(\dirname(__DIR__, 3).'/translations/*+intl-icu.de.yaml') ?: [] as $file) {
            $domain = explode('+', basename($file))[0];
            if (\in_array($domain, self::STAFF, true)) {
                continue;
            }
            $parsed = Yaml::parseFile($file);
            self::assertIsArray($parsed);
            $catalogues[$domain] = self::flatten($parsed);
        }
        self::assertArrayHasKey('search', $catalogues);

        return $catalogues;
    }

    /**
     * @param array<mixed> $tree
     *
     * @return array<string, string>
     */
    private static function flatten(array $tree, string $prefix = ''): array
    {
        $flat = [];
        foreach ($tree as $key => $value) {
            if (\is_array($value)) {
                $flat += self::flatten($value, $prefix.$key.'.');
            } elseif (\is_scalar($value)) {
                $flat[$prefix.$key] = (string) $value;
            }
        }

        return $flat;
    }
}
