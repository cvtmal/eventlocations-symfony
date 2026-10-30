<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\ValueObject\RatingLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RatingLabelTest extends TestCase
{
    /**
     * @return iterable<string, array{float|string, ?RatingLabel}>
     */
    public static function scores(): iterable
    {
        yield '0.0' => [0.0, null];
        yield '7.6' => [7.6, null];
        yield '7.7' => [7.7, RatingLabel::Recommended];
        yield '7.9' => [7.9, RatingLabel::Recommended];
        yield '8.0' => [8.0, RatingLabel::Excellent];
        yield '8.9' => [8.9, RatingLabel::Excellent];
        yield '9.0' => [9.0, RatingLabel::Outstanding];
        yield '10.0' => [10.0, RatingLabel::Outstanding];
        yield 'string 8.4' => ['8.4', RatingLabel::Excellent];
        yield 'string 7.7' => ['7.7', RatingLabel::Recommended];
    }

    #[DataProvider('scores')]
    public function test_label_from_score(float|string $score, ?RatingLabel $expected): void
    {
        self::assertSame($expected, RatingLabel::fromScore($score));
    }
}
