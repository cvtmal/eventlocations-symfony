<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Portal\Command\RequestPortalPasswordReset;
use App\Portal\Form\PortalEmailData;
use App\Portal\Form\PortalEmailType;
use App\Portal\ValueObject\PortalFlash;
use App\Shared\Security\ClientIpKey;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Same answer for every address, and the same limiter as registering, since both can send a mail.
 */
final class ForgotPasswordController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly RateLimiterFactoryInterface $portalRegisterLimiter,
        private readonly ClientIpKey $clientIpKey,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/konto/passwort-vergessen', name: 'portal_forgot_password', methods: ['GET', 'POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(PortalEmailType::class)->handleRequest($request);
        if (!$form->isSubmitted()) {
            return $this->page($form);
        }

        if (!$form->isValid()) {
            return $this->page($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $limit = $this->portalRegisterLimiter->create($this->clientIpKey->of($request))->consume();
        if (!$limit->isAccepted()) {
            $form->addError(new FormError($this->translator->trans('register.error.rate_limited', [], 'portal')));
            $response = $this->page($form, Response::HTTP_TOO_MANY_REQUESTS);
            $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

            return $response;
        }

        $data = $form->getData();
        \assert($data instanceof PortalEmailData && null !== $data->email);
        $this->commandBus->dispatch(new RequestPortalPasswordReset($data->email));

        $this->addFlash('success', PortalFlash::message('flash.reset_sent'));

        return $this->redirectToRoute('portal_login', status: Response::HTTP_SEE_OTHER);
    }

    private function page(FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        return $this->render('portal/security/forgot.html.twig', ['form' => $form], new Response(status: $status));
    }
}
