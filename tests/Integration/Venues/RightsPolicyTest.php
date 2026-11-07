<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Tests\Support\Cms\ResetsContentBlocks;
use App\Venues\Entity\RightsPolicyVersion;
use App\Venues\Extension\RightsDeclarationBlock;
use App\Venues\RightsPolicy;
use App\Venues\ValueObject\RightsPolicyText;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The declaration text in force (G1) and the version it stands for: the catalogue default until staff
 * write their own in the text block, a content-addressed version, and `record()` keeping the words
 * of a version once. Each test runs in a rolled-back transaction.
 */
final class RightsPolicyTest extends KernelTestCase
{
    use ResetsContentBlocks;

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $this->resetContentBlocks();
    }

    protected function tearDown(): void
    {
        $this->resetContentBlocks();
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_the_default_is_the_text_of_the_venues_catalogue(): void
    {
        $text = $this->policy()->current();

        self::assertSame(trim($this->translator()->trans('rights.declaration.default', [], 'venues', 'de')), $text->markdown);
        self::assertNotSame('rights.declaration.default', $text->markdown, 'the key exists in the catalogue');
        self::assertStringStartsWith('**Bildrechte-Erklärung**', $text->markdown);
        self::assertSame(RightsPolicyText::of($text->markdown)->version, $text->version);
    }

    public function test_the_default_is_a_du_text_that_fits_the_block_and_names_the_revocation(): void
    {
        $markdown = $this->policy()->current()->markdown;

        self::assertLessThanOrEqual((new RightsDeclarationBlock())->maxLength(), mb_strlen($markdown), 'staff can save the default unchanged');
        self::assertStringContainsString('Du darfst diese Location vertreten', $markdown);
        self::assertStringContainsString('Du kannst eine Freigabe jederzeit widerrufen', $markdown);
        self::assertStringContainsString('Grössen', $markdown, 'Swiss spelling');
        self::assertDoesNotMatchRegularExpression('/\b(Sie|Ihre?[mnrs]?)\b/u', $markdown, 'du, never Sie');
        self::assertStringNotContainsString('ß', $markdown);
        self::assertStringContainsString('1. ', $markdown);
        self::assertStringContainsString('4. ', $markdown);
    }

    public function test_the_label_and_the_placement_of_the_block_are_in_the_catalogue(): void
    {
        $block = new RightsDeclarationBlock();

        self::assertSame('rights.declaration', $block->key());
        self::assertSame('Bildrechte-Erklärung', $block->label()->trans($this->translator(), 'de'));
        self::assertStringContainsString('Fotos-Tab', $block->placement()->trans($this->translator(), 'de'));
        self::assertNull($block->fallback(), 'the default is several paragraphs, hence pageDefault()');
        self::assertCount(1, $block->pageDefault());
    }

    public function test_a_text_block_of_the_staff_wins_over_the_default(): void
    {
        $default = $this->policy()->current();

        $this->saveBlock("  **Bildrechte**\n\nDu bestätigst die Rechte an allen Fotos.\n\n");

        $text = $this->policy()->current();
        self::assertSame("**Bildrechte**\n\nDu bestätigst die Rechte an allen Fotos.", $text->markdown, 'trimmed, otherwise as written');
        self::assertNotSame($default->version, $text->version);
        self::assertSame(RightsPolicyText::of($text->markdown)->version, $text->version);
    }

    public function test_a_block_in_another_locale_does_not_count(): void
    {
        $default = $this->policy()->current();

        $this->saveBlock('Tu confirmes les droits.', 'fr');

        self::assertEquals($default, $this->policy()->current());
    }

    public function test_the_version_follows_the_words_and_nothing_else(): void
    {
        $this->saveBlock("Der Wortlaut.\n");
        $first = $this->policy()->current();
        $this->connection->executeStatement("UPDATE content_blocks SET body_markdown = 'Der Wortlaut.', updated_at = updated_at + INTERVAL '1 day' WHERE key = 'rights.declaration'");
        $this->resetContentBlocks();
        $again = $this->policy()->current();
        $this->connection->executeStatement("UPDATE content_blocks SET body_markdown = 'Der Wortlaut!' WHERE key = 'rights.declaration'");
        $this->resetContentBlocks();
        $edited = $this->policy()->current();

        self::assertSame($first->version, $again->version, 'the same words after the surrounding whitespace and the save time are the same version');
        self::assertNotSame($first->version, $edited->version);
    }

    public function test_the_current_html_is_safe_markdown(): void
    {
        $html = $this->policy()->currentHtml();

        self::assertStringContainsString('<strong>Bildrechte-Erklärung</strong>', $html);
        self::assertStringContainsString('<ol>', $html);
        self::assertSame(4, substr_count($html, '<li>'));

        $this->saveBlock("<script>alert(1)</script>\n\n[x](javascript:alert(1)) und **fett**");
        $html = $this->policy()->currentHtml();

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('<strong>fett</strong>', $html);
    }

    public function test_a_heading_of_the_staff_steps_down_so_the_page_that_shows_it_keeps_one_h1(): void
    {
        $this->saveBlock("# Bildrechte\n\n## Unter\n\nText.");

        $html = $this->policy()->currentHtml();

        self::assertStringContainsString('<h2>Bildrechte</h2>', $html);
        self::assertStringContainsString('<h2>Unter</h2>', $html);
        self::assertStringNotContainsString('<h1', $html);
    }

    public function test_record_writes_the_text_of_a_version_once(): void
    {
        $text = RightsPolicyText::of("**Bildrechte**\n\nDer Wortlaut.");

        $version = $this->policy()->record($text);
        self::assertSame(0, $this->versions($text->version), 'record() persists, the caller flushes');
        $this->em->flush();

        self::assertSame($text->version, $version->version());
        self::assertSame($text->markdown, $version->bodyMarkdown());
        self::assertSame(1, $this->versions($text->version));
        self::assertSame($version, $this->policy()->record($text), 'the same unit of work: the same row');
        $this->em->flush();
        self::assertSame(1, $this->versions($text->version));

        $this->em->clear();
        $found = $this->policy()->record($text);
        $this->em->flush();
        self::assertSame(1, $this->versions($text->version), 'the next request finds the row of the first');
        self::assertSame($text->markdown, $found->bodyMarkdown());
    }

    public function test_record_before_the_flush_is_idempotent_too(): void
    {
        $text = RightsPolicyText::of('Zwei Fotos, ein Wortlaut.');

        $first = $this->policy()->record($text);
        $second = $this->policy()->record($text);
        $this->em->flush();

        self::assertSame($first, $second);
        self::assertSame(1, $this->versions($text->version));
    }

    public function test_other_words_make_another_row_and_leave_the_first_alone(): void
    {
        $first = RightsPolicyText::of('Der erste Wortlaut.');
        $second = RightsPolicyText::of('Der zweite Wortlaut.');

        $this->policy()->record($first);
        $this->policy()->record($second);
        $this->em->flush();

        self::assertSame(
            ['Der erste Wortlaut.', 'Der zweite Wortlaut.'],
            $this->connection->fetchFirstColumn('SELECT body_markdown FROM rights_policy_versions WHERE version IN (?, ?) ORDER BY body_markdown', [$first->version, $second->version]),
        );
    }

    public function test_the_legacy_version_is_the_row_of_the_migration(): void
    {
        $legacy = $this->policy()->legacy();

        self::assertSame('legacy', RightsPolicy::LEGACY);
        self::assertSame('legacy', $legacy->version());
        self::assertInstanceOf(RightsPolicyVersion::class, $this->em->find(RightsPolicyVersion::class, 'legacy'));
        self::assertNotSame('', trim($legacy->bodyMarkdown()));
    }

    private function policy(): RightsPolicy
    {
        $policy = self::getContainer()->get(RightsPolicy::class);
        self::assertInstanceOf(RightsPolicy::class, $policy);

        return $policy;
    }

    private function translator(): TranslatorInterface
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return $translator;
    }

    /**
     * What a save in the text block screen leaves: a row, read from a fresh snapshot.
     */
    private function saveBlock(string $body, string $locale = 'de'): void
    {
        $this->connection->executeStatement(
            "INSERT INTO content_blocks (key, locale, body_markdown, updated_at) VALUES ('rights.declaration', ?, ?, CURRENT_TIMESTAMP)",
            [$locale, $body],
        );
        $this->resetContentBlocks();
    }

    private function versions(string $version): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM rights_policy_versions WHERE version = ?', [$version]);
    }
}
