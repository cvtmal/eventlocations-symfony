<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Portal\Command\ChangePortalPassword;
use App\Portal\Command\UpdatePortalProfile;
use App\Portal\Form\ChangePasswordData;
use App\Portal\Form\ChangePasswordType;
use App\Portal\Form\ProfileData;
use App\Portal\Form\ProfileType;
use App\Portal\ReadModel\PortalProfileQuery;
use App\Portal\Security\PortalIdentity;
use App\Portal\ValueObject\PortalFlash;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Two named forms on one route: a form counts as submitted only when its name is in the POST body. A changed
 * password ends the session on the next request, so that form goes on to the login, where the flash shows.
 */
final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly PortalProfileQuery $profiles,
        private readonly FormFactoryInterface $forms,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/konto/profil', name: 'portal_profile', methods: ['GET', 'POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(Request $request, #[CurrentUser] PortalIdentity $identity): Response
    {
        $profile = $this->profiles->find($identity->id()) ?? throw $this->createNotFoundException();

        $details = new ProfileData($profile->firstName, $profile->lastName, $profile->phone);
        $profileForm = $this->forms->createNamed('profile', ProfileType::class, $details)->handleRequest($request);

        if ($profileForm->isSubmitted() && $profileForm->isValid()) {
            \assert(null !== $details->firstName && null !== $details->lastName);
            $this->commandBus->dispatch(new UpdatePortalProfile($identity->id(), $details->firstName, $details->lastName, $details->phone));

            $this->addFlash('success', PortalFlash::message('flash.profile_saved'));

            return $this->redirectToRoute('portal_profile', status: Response::HTTP_SEE_OTHER);
        }

        $password = new ChangePasswordData();
        $passwordForm = $this->forms->createNamed('password', ChangePasswordType::class, $password)->handleRequest($request);

        if ($passwordForm->isSubmitted() && $passwordForm->isValid()) {
            \assert(null !== $password->plainPassword);
            $this->commandBus->dispatch(new ChangePortalPassword($identity->id(), $password->plainPassword));

            $this->addFlash('success', PortalFlash::message('flash.password_changed'));

            return $this->redirectToRoute('portal_login', status: Response::HTTP_SEE_OTHER);
        }

        $invalid = $profileForm->isSubmitted() || $passwordForm->isSubmitted();

        return $this->render('portal/profile.html.twig', [
            'email' => $profile->email,
            'profile_form' => $profileForm,
            'password_form' => $passwordForm,
        ], new Response(status: $invalid ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
