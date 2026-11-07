<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared;

use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Venues\DetailVenues;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * S1, S3, ADM-6: the back office's JS, CSS and icons never reach a public page. Each marker is also shown to
 * exist on an admin page, so a renamed path cannot leave the first test passing on a dead string.
 */
final class PublicPagesLoadNoAdminAssetsTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use ImportsTownDataset;

    private const array MARKERS = ['/admin-build/', '/assets/js/admin/', 'admin-sprite', '/assets/styles/admin', 'data-island'];

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicPaths(): iterable
    {
        yield 'listing' => ['/eventlokale'];
        yield 'detail' => ['/eventlokal/'.DetailVenues::FULL_SLUG];
    }

    #[DataProvider('publicPaths')]
    public function test_public_pages_carry_no_admin_marker(string $path): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        DetailVenues::full($em);

        $this->client->request('GET', $path);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        foreach (self::MARKERS as $marker) {
            self::assertStringNotContainsString($marker, $html, $path);
        }
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    public function test_the_markers_are_real(): void
    {
        $this->logInAs();

        $this->client->request('GET', '/admin/_styleguide');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        foreach (self::MARKERS as $marker) {
            self::assertStringContainsString($marker, $html);
        }
    }

    public function test_public_templates_use_nothing_of_the_back_office(): void
    {
        $root = \dirname(__DIR__, 3).'/templates';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $checked = 0;

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            if (str_starts_with($file->getPathname(), $root.'/admin/') || str_starts_with($file->getPathname(), $root.'/portal/')) {
                continue;
            }

            $template = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression('/\badmin_\w+\(/', $template, $file->getPathname());
            self::assertStringNotContainsString("'admin/", $template, $file->getPathname());
            ++$checked;
        }

        self::assertGreaterThan(0, $checked);
    }

    public function test_the_public_stylesheet_holds_no_admin_tokens(): void
    {
        $built = glob(\dirname(__DIR__, 3).'/var/sass/app-*.output.css') ?: [];
        if (1 !== \count($built)) {
            self::fail('Run make sass first (expected exactly one var/sass/app-*.output.css).');
        }

        self::assertStringNotContainsString('--a-accent', (string) file_get_contents($built[0]));
    }
}
