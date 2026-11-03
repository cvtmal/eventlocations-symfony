<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Portal\Command\ResetPortalPassword;
use App\Portal\Form\PortalPasswordData;
use App\Portal\Form\PortalPasswordType;
use App\Portal\Security\PortalLinkLogin;
use App\Portal\Security\PortalLinkSigner;
use App\Portal\ValueObject\PortalFlash;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The page of the mail's link: choosing a first password after registering or a staff invitation, or a new one
 * after forgetting it. A GET only shows the form, so a mail gateway that fetches the link changes nothing. The
 * link is verified on both methods, so a form left open dies with the link. Saving logs the person in and opens
 * `/konto`: whoever holds the link could set the password and log in anyway.
 *
 * A dead link answers in place, with no redirect and no flash: this request comes from webmail, a cross-site
 * navigation, and a SameSite=Strict cookie set on its first response is not sent on the redirect that follows.
 * The success flash does arrive: its POST is same-site.
 */
final class ResetPasswordController extends AbstractController
{
    public function __construct(
        private readonly PortalLinkSigner $links,
        private readonly MessageBusInterface $commandBus,
        private readonly PortalLinkLogin $login,
    ) {
    }

    #[Route('/konto/passwort-neu/{token}', name: 'portal_reset_password', methods: ['GET', 'POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(Request $request, string $token): Response
    {
        $id = $this->links->resetUserId($token);
        if (null === $id) {
            return $this->render('portal/security/notice.html.twig', [
                'title' => 'reset.invalid.title',
                'text' => 'reset.invalid.text',
                'link' => 'reset.invalid.link',
                'link_route' => 'portal_forgot_password',
            ], new Response(status: Response::HTTP_GONE));
        }

        $form = $this->createForm(PortalPasswordType::class)->handleRequest($request);
        if (!$form->isSubmitted()) {
            return $this->render('portal/security/reset.html.twig', ['form' => $form]);
        }

        if (!$form->isValid()) {
            return $this->render('portal/security/reset.html.twig', ['form' => $form], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $data = $form->getData();
        \assert($data instanceof PortalPasswordData && null !== $data->plainPassword);
        $this->commandBus->dispatch(new ResetPortalPassword($id, $data->plainPassword));
        $this->login->logIn($id);

        $this->addFlash('success', PortalFlash::message('flash.password_reset'));

        return $this->redirectToRoute('portal_home', status: Response::HTTP_SEE_OTHER);
    }
}
