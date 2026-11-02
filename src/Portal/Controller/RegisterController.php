<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Portal\Command\RegisterPortalUser;
use App\Portal\Exception\PortalEmailTaken;
use App\Portal\Form\RegisterData;
use App\Portal\Form\RegisterType;
use App\Portal\ValueObject\PortalFlash;
use App\Shared\Security\ClientIpKey;
use App\Shared\Translation\LocalizedRoutes;
use App\Shared\Uid\EntityId;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every path that accepted the form ends in the same 303, so nobody learns from it whether an address has an
 * account. A filled honeypot gets that answer too, with nothing stored. The limiter counts only what passed
 * validation, so typos cost a person nothing.
 */
final class RegisterController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly RateLimiterFactoryInterface $portalRegisterLimiter,
        private readonly ClientIpKey $clientIpKey,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/konto/registrieren', name: 'portal_register', methods: ['GET', 'POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(RegisterType::class)->handleRequest($request);
        if (!$form->isSubmitted()) {
            return $this->page($form);
        }

        $honeypot = $form->get('website')->getData();
        if (\is_string($honeypot) && '' !== trim($honeypot)) {
            return $this->checkInbox();
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

        try {
            $this->commandBus->dispatch($this->command($form->getData()));
        } catch (HandlerFailedException $exception) {
            if (!$exception->getPrevious() instanceof PortalEmailTaken) {
                throw $exception;
            }
        }

        return $this->checkInbox();
    }

    private function command(mixed $data): RegisterPortalUser
    {
        \assert($data instanceof RegisterData);
        \assert(null !== $data->email && null !== $data->firstName && null !== $data->lastName);

        return new RegisterPortalUser(EntityId::generate(), $data->email, $data->firstName, $data->lastName);
    }

    private function page(FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        return $this->render('portal/security/register.html.twig', ['form' => $form], new Response(status: $status));
    }

    private function checkInbox(): Response
    {
        $this->addFlash('success', PortalFlash::message('flash.check_inbox'));

        return $this->redirectToRoute('portal_login', status: Response::HTTP_SEE_OTHER);
    }
}
