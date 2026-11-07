<?php

declare(strict_types=1);

namespace App\Shared\Console;

use App\Shared\ValueObject\OgCard;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Writes one Open Graph card as SVG (docs/brand.md, "Share cards"). `make brand-png` renders the default card's SVG to
 * public/brand/og-default.png; F7b (a venue without a photo) and F8 (landing pages) call it with their texts. The default
 * card's title is the footer's tagline, so the card and the footer never say two things.
 */
#[AsCommand(name: 'app:brand:og-card', description: 'Write an Open Graph card (1200 × 630) as SVG')]
final class RenderOgCardCommand
{
    public function __construct(
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'The SVG file to write')]
        string $out = '',
        #[Option(description: 'default, venue or landing')]
        string $variant = 'default',
        #[Option(description: 'The line above the title: the place of a venue, the kind of a landing page')]
        string $eyebrow = '',
        #[Option(description: 'The venue name or the landing heading (the default card: the tagline)')]
        string $title = '',
        #[Option(description: 'The line under the title, e.g. "20–120 Personen · Seminar"')]
        string $facts = '',
    ): int {
        $card = match ($variant) {
            'default' => OgCard::default('' !== $title ? $title : $this->translator->trans('layout.footer.tagline')),
            'venue' => OgCard::venue($title, $eyebrow, $facts),
            'landing' => OgCard::landing($eyebrow, $title, $facts),
            default => null,
        };
        if (null === $card || ('default' !== $variant && '' === trim($title))) {
            $io->error('--variant is default, venue or landing; venue and landing need a --title.');

            return Command::INVALID;
        }
        if ('' === $out) {
            $io->error('--out names the SVG file to write.');

            return Command::INVALID;
        }

        $dir = \dirname($out);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            $io->error(\sprintf('%s cannot be created.', $dir));

            return Command::FAILURE;
        }
        file_put_contents($out, $this->twig->render('brand/og_card.svg.twig', ['card' => $card]));
        $io->writeln(\sprintf('Wrote %s', $out));

        return Command::SUCCESS;
    }
}
