<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Command\EnrolAdminTotp;
use App\Admin\Form\TotpEnrolmentData;
use App\Admin\Form\TotpEnrolmentType;
use App\Admin\Security\AdminIdentity;
use App\Admin\Security\AdminUserProvider;
use App\Admin\Security\TotpQrCode;
use App\Admin\Security\TotpSecretCipher;
use App\Shared\Translation\LocalizedRoutes;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Markup;

/**
 * First-login enrolment (S12; the bundle has none). The secret stays encrypted in the session until the
 * user has proved with a code that their app holds it; only then does it reach admin_users.
 */
#[IsGranted('ROLE_EDITOR')]
final class TotpEnrolmentController extends AbstractController
{
    private const string PENDING_SECRET = 'admin.totp_pending';

    #[Route('/admin/2fa/einrichten', name: 'admin_totp_enrol', methods: ['GET', 'POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(
        Request $request,
        #[CurrentUser] AdminIdentity $identity,
        TotpAuthenticatorInterface $totp,
        TotpSecretCipher $cipher,
        TotpQrCode $qrCode,
        MessageBusInterface $commandBus,
        AdminUserProvider $users,
        TokenStorageInterface $tokenStorage,
        TranslatorInterface $translator,
    ): Response {
        if ($identity->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('admin_dashboard');
        }

        $session = $request->getSession();
        $pending = $session->get(self::PENDING_SECRET);
        if (!\is_string($pending)) {
            $pending = $cipher->encrypt($totp->generateSecret());
            $session->set(self::PENDING_SECRET, $pending);
        }
        $secret = $cipher->decrypt($pending);
        $candidate = $identity->withTotpSecret($secret);

        $data = new TotpEnrolmentData();
        $form = $this->createForm(TotpEnrolmentType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($totp->checkCode($candidate, (string) $data->code)) {
                $commandBus->dispatch(new EnrolAdminTotp($identity->id(), $secret));
                $session->remove(self::PENDING_SECRET);
                // The session's fingerprint must match the new secret, or this user's own enrolment would log them out.
                $tokenStorage->getToken()?->setUser($users->refreshUser($identity));
                $this->addFlash('success', 'flash.totp_enrolled');

                return $this->redirectToRoute('admin_dashboard');
            }

            $form->get('code')->addError(new FormError($translator->trans('totp.error.invalid', [], 'admin')));
        }

        return $this->render('admin/security/totp_enrol.html.twig', [
            'form' => $form,
            'qr_code' => new Markup($qrCode->svg($totp->getQRContent($candidate)), 'UTF-8'),
            'secret_groups' => implode(' ', str_split($secret, 4)),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
