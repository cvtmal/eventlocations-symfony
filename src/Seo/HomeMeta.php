<?php

declare(strict_types=1);

namespace App\Seo;

use App\Seo\ValueObject\PageMeta;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * SEO-1 for the home page (#36). Its title and description are the catalogue's; staff cannot edit them.
 */
final class HomeMeta
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly TitleChain $titles,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%site.name%')]
        private readonly string $site,
    ) {
    }

    public function for(): PageMeta
    {
        return new PageMeta(
            $this->titles->fit([['home.title', []], ['home.title_short', []]], $this->site),
            MetaDescription::fit($this->translator->trans('home.description', [], 'seo')),
            $this->urls->generate('home', [], UrlGeneratorInterface::ABSOLUTE_URL),
            false,
            'website',
        );
    }
}
