<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cms\Form;

use App\Cms\Exception\ContentTooLong;
use App\Cms\Form\LandingLocaleTextsData;
use App\Cms\ValueObject\LandingKey;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The form, the handler and `char-counter.js` count a text alike (#26, Rs Q4): code points of the trimmed
 * text, an emoji as one. One set of texts proves it on both sides: `admin-ui/tests/fixtures/length-count.json`
 * goes through the real rules here and through the real script in `vanilla-scripts.test.js`, so a title the
 * counter shows as "60 / 60" saves and one more character does not.
 */
final class LengthCountTest extends TestCase
{
    private const string VECTORS = __DIR__.'/../../../../admin-ui/tests/fixtures/length-count.json';

    public function test_the_shared_limit_is_the_title_limit(): void
    {
        self::assertSame(LandingKey::TITLE_MAX_LENGTH, self::vectors()['limit']);
    }

    public function test_sixty_code_points_with_a_crlf_and_an_emoji_are_a_valid_title(): void
    {
        $title = self::vectors()['fits'];

        self::assertCount(0, self::validator()->validateProperty(self::data($title), 'title'));
        ContentTooLong::check($title, 'title', 'de', LandingKey::TITLE_MAX_LENGTH);
    }

    public function test_sixty_one_code_points_are_refused_by_the_form_and_the_handler(): void
    {
        $title = self::vectors()['over'];

        $violations = self::validator()->validateProperty(self::data($title), 'title');
        self::assertCount(1, $violations);
        self::assertSame('cms.landing.too_long', $violations->get(0)->getMessageTemplate());

        $this->expectException(ContentTooLong::class);
        ContentTooLong::check($title, 'title', 'de', LandingKey::TITLE_MAX_LENGTH);
    }

    /**
     * @return array{limit: int, fits: string, over: string}
     */
    private static function vectors(): array
    {
        /** @var array{limit: int, fits: string, over: string} $vectors */
        $vectors = json_decode((string) file_get_contents(self::VECTORS), true, flags: \JSON_THROW_ON_ERROR);

        return $vectors;
    }

    private static function data(string $title): LandingLocaleTextsData
    {
        $data = new LandingLocaleTextsData();
        $data->title = $title;

        return $data;
    }

    private static function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}
