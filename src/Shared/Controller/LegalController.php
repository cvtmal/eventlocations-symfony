<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Impressum and Datenschutz (#18). The company facts come from the `site.*` parameters and the
 * retention from the purge's own parameter, so the pages cannot drift from what the code does.
 */
final class LegalController extends AbstractController
{
    /**
     * Lifetime of #13's `merkliste` cookie (`MerklisteCookie::MAX_AGE_SECONDS`, renewed on every
     * write), pinned by `LegalPagesTest`. The page says "bis zu", because Safari may cut it short.
     */
    public const int MERKLISTE_DAYS = 400;

    public function __construct(
        #[Autowire('%site.operator_name%')]
        private readonly string $operatorName,
        #[Autowire('%site.operator_street%')]
        private readonly string $operatorStreet,
        #[Autowire('%site.operator_postcode%')]
        private readonly string $operatorPostcode,
        #[Autowire('%site.operator_city%')]
        private readonly string $operatorCity,
        #[Autowire('%site.operator_uid%')]
        private readonly string $operatorUid,
        #[Autowire('%site.contact_email%')]
        private readonly string $contactEmail,
        #[Autowire('%inquiries.retention_months%')]
        private readonly int $retentionMonths,
    ) {
    }

    #[Route(path: ['de' => '/impressum'], name: 'impressum', methods: ['GET'])]
    #[Cache(public: true, smaxage: 600, staleWhileRevalidate: 86400)]
    public function impressum(): Response
    {
        return $this->render('site/legal/impressum.html.twig', [
            'site' => $this->site(),
        ]);
    }

    #[Route(path: ['de' => '/datenschutz'], name: 'datenschutz', methods: ['GET'])]
    #[Cache(public: true, smaxage: 600, staleWhileRevalidate: 86400)]
    public function datenschutz(): Response
    {
        return $this->render('site/legal/datenschutz.html.twig', [
            'site' => $this->site(),
            'retention_months' => $this->retentionMonths,
            'merkliste_days' => self::MERKLISTE_DAYS,
        ]);
    }

    /**
     * @return array{operator: string, street: string, postcode: string, city: string, uid: string, email: string}
     */
    private function site(): array
    {
        return [
            'operator' => $this->operatorName,
            'street' => $this->operatorStreet,
            'postcode' => $this->operatorPostcode,
            'city' => $this->operatorCity,
            'uid' => $this->operatorUid,
            'email' => $this->contactEmail,
        ];
    }
}
