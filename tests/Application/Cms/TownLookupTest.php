<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\AdminSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TownLookupTest extends WebTestCase
{
    use AdminSession;
    use ImportsTownDataset;

    public function test_a_name_gives_zip_and_town_with_the_canton(): void
    {
        $this->logInAs(AdminRole::Editor);

        $options = $this->lookup('meilen');

        self::assertResponseIsSuccessful();
        self::assertSame(['value' => '8706 Meilen', 'label' => '8706 Meilen', 'detail' => 'Zürich'], $options[0]);
    }

    public function test_a_zip_keeps_the_zip_that_matched(): void
    {
        $this->logInAs(AdminRole::Editor);

        self::assertSame('8712 Stäfa', $this->lookup('8712')[0]['value']);
    }

    public function test_an_umlaut_is_folded_and_cantons_are_left_out(): void
    {
        $this->logInAs(AdminRole::Editor);

        $options = $this->lookup('zuerich');

        self::assertNotEmpty($options);
        self::assertSame('8001 Zürich', $options[0]['value']);
        foreach ($options as $option) {
            self::assertMatchesRegularExpression('/\A\d{4} \S/u', $option['value']);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusable(): iterable
    {
        yield 'no q' => [''];
        yield 'array' => ['?q[]=meilen'];
        yield 'invalid UTF-8' => ['?q=%ff%fe'];
        yield 'NUL' => ['?q=mei%00len'];
        yield 'one ZIP digit' => ['?q=8'];
        yield 'one letter' => ['?q=%20m%20'];
        yield 'punctuation' => ['?q=!!'];
    }

    #[DataProvider('unusable')]
    public function test_an_unusable_query_gives_no_options(string $query): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('GET', '/admin/api/cms/towns'.$query);

        self::assertResponseIsSuccessful();
        self::assertSame('{"options":[]}', $this->client->getResponse()->getContent());
    }

    public function test_an_anonymous_visitor_gets_a_json_401(): void
    {
        $this->client->request('GET', '/admin/api/cms/towns?q=meilen');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    /**
     * @return list<array{value: string, label: string, detail: string}>
     */
    private function lookup(string $q): array
    {
        $this->client->request('GET', '/admin/api/cms/towns?q='.rawurlencode($q));
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['options']);
        $options = [];
        foreach ($body['options'] as $option) {
            self::assertIsArray($option);
            self::assertIsString($option['value']);
            self::assertIsString($option['label']);
            self::assertIsString($option['detail']);
            $options[] = ['value' => $option['value'], 'label' => $option['label'], 'detail' => $option['detail']];
        }

        return $options;
    }
}
