<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Admin\ReadModel\AuditLogQuery;
use App\Cms\Form\ChangedFields;
use App\Cms\Form\TierData;
use App\Cms\Form\TierType;
use App\Cms\ValueObject\CmsFlash;
use App\Cms\ValueObject\TierCode;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Exception\TierNameTaken;
use App\Venues\Exception\TierNotFound;
use App\Venues\ReadModel\TierDefinition;
use App\Venues\ReadModel\TierDefinitionsQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One tier (F12): PRG with a 303 back to the list, a 422 on invalid input, a 404 for a code the database does not
 * hold. A save that changes nothing dispatches nothing, so it leaves no audit row and no cache purge. No version
 * token: at most two staff work here, and the last save wins.
 *
 * A name another tier has is refused by the form before anything is dispatched; one that was taken in between comes
 * back from the handler as `TierNameTaken` and lands on the same field, so a rename never ends as a 500.
 */
#[IsGranted('ROLE_EDITOR')]
final class TierEditController extends AbstractController
{
    public function __construct(
        private readonly TierDefinitionsQuery $tiers,
        private readonly AuditLogQuery $auditLog,
        private readonly MessageBusInterface $commandBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/tarife/{code}', name: 'admin_tiers_edit', requirements: ['code' => TierCode::REQUIREMENT], methods: ['GET', 'POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(string $code, Request $request): Response
    {
        $tier = $this->tiers->byCode($code) ?? throw $this->createNotFoundException();

        $data = TierData::from($tier);
        $form = $this->createForm(TierType::class, $data, ['code' => $code]);
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            return $this->renderEditor($tier, $form, $request->isMethod('POST') ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        }
        if (!$form->isValid()) {
            return $this->renderEditor($tier, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $changed = ChangedFields::between(TierData::from($tier), $data);
        if ([] === $changed) {
            $this->addFlash('success', CmsFlash::message('tiers.flash.unchanged', ['name' => $tier->name]));

            return $this->redirectToRoute('admin_tiers', [], Response::HTTP_SEE_OTHER);
        }

        try {
            $this->commandBus->dispatch($data->toCommand($code, $changed));
        } catch (HandlerFailedException $failure) {
            if ([] !== $failure->getWrappedExceptions(TierNotFound::class)) {
                throw $this->createNotFoundException();
            }
            if ([] !== $failure->getWrappedExceptions(TierNameTaken::class)) {
                $form->get('name')->addError(new FormError($this->translator->trans('cms.tier.name.taken', [], 'validators')));

                return $this->renderEditor($tier, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            throw $failure;
        }

        $this->addFlash('success', CmsFlash::message('tiers.flash.saved', ['name' => (string) $data->name]));

        return $this->redirectToRoute('admin_tiers', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function renderEditor(TierDefinition $tier, FormInterface $form, int $status): Response
    {
        return $this->render('admin/cms/tiers/edit.html.twig', [
            'tier' => $tier,
            'form' => $form,
            'last_edit' => $this->auditLog->forSubject('tier', $tier->code, 1)[0] ?? null,
        ], new Response(status: $status));
    }
}
