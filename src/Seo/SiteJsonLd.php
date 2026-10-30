<?php

declare(strict_types=1);

namespace App\Seo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * SEO-2: WebSite and Organization, on every page through base.html.twig. No database. No `logo`
 * until the brand design (Q1).
 */
final class SiteJsonLd
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%site.name%')]
        private readonly string $name,
        #[Autowire('%site.operator_name%')]
        private readonly string $operatorName,
        #[Autowire('%site.operator_street%')]
        private readonly string $operatorStreet,
        #[Autowire('%site.operator_postcode%')]
        private readonly string $operatorPostcode,
        #[Autowire('%site.operator_city%')]
        private readonly string $operatorCity,
        #[Autowire('%site.contact_email%')]
        private readonly string $contactEmail,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $home = $this->urls->generate('home', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'WebSite',
                    '@id' => $home.'#website',
                    'name' => $this->name,
                    'url' => $home,
                    'inLanguage' => 'de-CH',
                    'publisher' => ['@id' => $home.'#organization'],
                ],
                [
                    '@type' => 'Organization',
                    '@id' => $home.'#organization',
                    'name' => $this->name,
                    'legalName' => $this->operatorName,
                    'url' => $home,
                    'email' => $this->contactEmail,
                    'address' => [
                        '@type' => 'PostalAddress',
                        'streetAddress' => $this->operatorStreet,
                        'postalCode' => $this->operatorPostcode,
                        'addressLocality' => $this->operatorCity,
                        'addressCountry' => 'CH',
                    ],
                ],
            ],
        ];
    }
}
