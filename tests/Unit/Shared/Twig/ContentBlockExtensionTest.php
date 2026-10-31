<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Twig;

use App\Shared\Extension\ContentBlockDefinition;
use App\Shared\Extension\ContentBlockRegistry;
use App\Shared\Markdown\MarkdownRenderer;
use App\Shared\ReadModel\ContentBlocks;
use App\Shared\Twig\ContentBlockExtension;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatableInterface;

final class ContentBlockExtensionTest extends TestCase
{
    private const string DECLARED = 'fake.declared';
    private const string SILENT = 'fake.silent';

    public function test_a_set_block_renders_as_markdown_in_the_request_locale(): void
    {
        $extension = $this->extension(['de' => ['listing.intro' => 'Hallo **Welt**'], 'fr' => ['listing.intro' => 'Bonjour']], 'fr');

        self::assertSame("<p>Bonjour</p>\n", $extension->block('listing.intro', 'Ersatz'));
        self::assertSame('Bonjour', $extension->text('listing.intro'));
    }

    public function test_without_a_request_the_default_locale_is_used(): void
    {
        $extension = $this->extension(['de' => ['listing.intro' => 'Hallo **Welt**']], null);

        self::assertSame("<p>Hallo <strong>Welt</strong></p>\n", $extension->block('listing.intro'));
        self::assertSame("<p>Hallo <strong>Welt</strong></p>\n", $extension->peek('listing.intro'));
    }

    public function test_an_unset_block_renders_the_escaped_fallback_or_nothing(): void
    {
        $extension = $this->extension(['de' => []], 'de');

        self::assertSame('<p>5 Locations &lt;b&gt; &amp; mehr</p>', $extension->block('listing.intro', '5 Locations <b> & mehr'));
        self::assertSame('', $extension->block('listing.intro', '  '));
        self::assertSame('', $extension->block('listing.intro'));
        self::assertSame('', $extension->peek('footer.note'));
        self::assertSame('', $extension->text('landing.canton.zh.title'));
    }

    public function test_a_definitions_fallback_renders_escaped_in_the_request_locale(): void
    {
        $german = $this->extension(['de' => []], 'de');
        self::assertSame('<p>Standard &lt;b&gt; &amp; mehr</p>', $german->block(self::DECLARED));
        self::assertSame('<p>Standard &lt;b&gt; &amp; mehr</p>', $german->peek(self::DECLARED));
        self::assertSame('Standard <b> & mehr', $german->text(self::DECLARED), 'content_text() gives raw text; Twig escapes it.');

        $french = $this->extension(['de' => []], 'fr');
        self::assertSame('<p>Par défaut</p>', $french->block(self::DECLARED));
        self::assertSame('<p>Par défaut</p>', $french->peek(self::DECLARED));
        self::assertSame('Par défaut', $french->text(self::DECLARED));
    }

    public function test_the_callers_fallback_wins_over_the_definitions(): void
    {
        self::assertSame('<p>Vom Aufrufer</p>', $this->extension(['de' => []], 'de')->block(self::DECLARED, 'Vom Aufrufer'));
    }

    public function test_a_stored_text_wins_over_every_fallback(): void
    {
        $extension = $this->extension(['de' => [self::DECLARED => 'Eigener Text']], 'de');

        self::assertSame("<p>Eigener Text</p>\n", $extension->block(self::DECLARED, 'Vom Aufrufer'));
        self::assertSame("<p>Eigener Text</p>\n", $extension->peek(self::DECLARED));
        self::assertSame('Eigener Text', $extension->text(self::DECLARED));
    }

    public function test_a_definition_without_a_fallback_renders_nothing(): void
    {
        $extension = $this->extension(['de' => []], 'de');

        self::assertSame('', $extension->block(self::SILENT));
        self::assertSame('', $extension->peek(self::SILENT));
        self::assertSame('', $extension->text(self::SILENT));
    }

    public function test_a_peek_on_a_cold_pool_shows_the_definitions_fallback_without_a_query(): void
    {
        self::assertSame('<p>Standard &lt;b&gt; &amp; mehr</p>', $this->extension(null, 'de')->peek(self::DECLARED));
    }

    /**
     * @param array<string, array<string, string>>|null $snapshot null is a cold pool
     */
    private function extension(?array $snapshot, ?string $requestLocale): ContentBlockExtension
    {
        $clock = new MockClock('2026-10-31 12:00:00 UTC');
        $pool = new ArrayAdapter();
        if (null !== $snapshot) {
            $pool->save($pool->getItem(ContentBlocks::ITEM)->set(['built_at' => $clock->now()->getTimestamp(), 'blocks' => $snapshot]));
        }

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');

        $requestStack = new RequestStack();
        if (null !== $requestLocale) {
            $request = new Request();
            $request->setLocale($requestLocale);
            $requestStack->push($request);
        }

        $translator = new Translator('de');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['fake.fallback' => 'Standard <b> & mehr'], 'de', 'shared');
        $translator->addResource('array', ['fake.fallback' => 'Par défaut'], 'fr', 'shared');

        $definitions = new ContentBlockRegistry([
            self::definition(self::DECLARED, new TranslatableMessage('fake.fallback', [], 'shared')),
            self::definition(self::SILENT, null),
        ]);

        return new ContentBlockExtension(new ContentBlocks($connection, $pool, new MarkdownRenderer(), $clock), $definitions, $translator, $requestStack, 'de');
    }

    private static function definition(string $key, ?TranslatableInterface $fallback): ContentBlockDefinition
    {
        return new class($key, $fallback) implements ContentBlockDefinition {
            public function __construct(
                private readonly string $key,
                private readonly ?TranslatableInterface $fallback,
            ) {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function label(): TranslatableInterface
            {
                return new TranslatableMessage('fake.label');
            }

            public function placement(): TranslatableInterface
            {
                return new TranslatableMessage('fake.placement');
            }

            public function maxLength(): int
            {
                return 100;
            }

            public function fallback(): ?TranslatableInterface
            {
                return $this->fallback;
            }
        };
    }
}
