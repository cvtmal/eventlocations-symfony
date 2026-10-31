<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Admin\ReadModel\AuditLogQuery;
use App\Cms\Command\SaveLandingTexts;
use App\Cms\Exception\ContentTooLong;
use App\Cms\Exception\StaleContentBlocks;
use App\Cms\Exception\UnknownLandingPage;
use App\Cms\Form\ContentChanges;
use App\Cms\Form\LandingTextsData;
use App\Cms\Form\LandingTextsType;
use App\Cms\ReadModel\ContentBlockStatusQuery;
use App\Cms\ValueObject\CmsFlash;
use App\Cms\ValueObject\LandingKey;
use App\Shared\Extension\LandingPageSource;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\LocalizedRoutes;
use App\Shared\ValueObject\LandingPageEntry;
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
 * The texts of one landing page (#26, CMS-12): intro, SEO title and meta description per locale, each
 * optional, with #17's text as the placeholder. A save is a 303 back here (PRG). Input equal to the stored
 * rows dispatches nothing (P9). A stale version is a 409 with the input kept, a text over its limit a 422 on
 * its field. The page must have at least one published venue, else 404.
 */
#[IsGranted('ROLE_EDITOR')]
final class LandingPageEditController extends AbstractController
{
    public function __construct(
        private readonly LandingPageSource $landingPages,
        private readonly ContentBlockStatusQuery $status,
        private readonly AuditLogQuery $auditLog,
        private readonly MessageBusInterface $commandBus,
        private readonly TranslatorInterface $translator,
        private readonly EnabledLocale $locales,
    ) {
    }

    #[Route('/admin/landingpages/{landing}', name: 'admin_landing_pages_edit', requirements: ['landing' => LandingKey::REQUIREMENT], methods: ['GET', 'POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(string $landing, Request $request): Response
    {
        $entry = $this->landingPages->page($landing) ?? throw $this->createNotFoundException();
        $keys = array_map($entry->blockKey(...), LandingPageEntry::FIELDS);
        $version = $this->status->versionOf($keys);
        $bodies = $this->status->bodies($keys);

        $data = LandingTextsData::from($entry, $bodies, $this->locales->all(), $version);
        $form = $this->createForm(LandingTextsType::class, $data, ['entry' => $entry]);
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            return $this->renderEditor($entry, $form, $request->isMethod('POST') ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        }
        if (!$form->isValid()) {
            return $this->renderEditor($entry, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $loaded = LandingTextsData::from($entry, $bodies, $this->locales->all(), $version);
        $changes = ContentChanges::between($loaded->locales, $data->locales);
        $flashParameters = ['name' => $entry->name];
        if ($changes->isEmpty()) {
            $this->addFlash('success', CmsFlash::message('landing.flash.unchanged', $flashParameters));

            return $this->backTo($entry);
        }

        try {
            $this->commandBus->dispatch(new SaveLandingTexts($entry->keyPrefix, $changes->texts, (string) $data->version, $changes->fields, $changes->locales));
        } catch (HandlerFailedException $failure) {
            if ([] !== $failure->getWrappedExceptions(UnknownLandingPage::class)) {
                throw $this->createNotFoundException();
            }
            if ([] !== $failure->getWrappedExceptions(StaleContentBlocks::class)) {
                $form->addError(new FormError($this->translator->trans('text.error.stale', [], 'cms')));

                return $this->renderEditor($entry, $form, Response::HTTP_CONFLICT, stale: true);
            }
            $tooLong = $failure->getWrappedExceptions(ContentTooLong::class);
            if ([] === $tooLong) {
                throw $failure;
            }
            $error = reset($tooLong);
            $form->get('locales')->get($error->locale)->get($error->field)
                ->addError(new FormError($this->translator->trans('cms.landing.too_long', ['limit' => $error->maxLength], 'validators')));

            return $this->renderEditor($entry, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', CmsFlash::message('landing.flash.saved', $flashParameters));

        return $this->backTo($entry);
    }

    private function backTo(LandingPageEntry $entry): Response
    {
        return $this->redirectToRoute('admin_landing_pages_edit', ['landing' => $entry->keyPrefix], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function renderEditor(LandingPageEntry $entry, FormInterface $form, int $status = Response::HTTP_OK, bool $stale = false): Response
    {
        return $this->render('admin/cms/pages/landing_edit.html.twig', [
            'entry' => $entry,
            'form' => $form,
            'stale' => $stale,
            'locales' => $this->localeTabs(),
            'last_edit' => $this->auditLog->forSubject('landing_page', $entry->keyPrefix, 1)[0] ?? null,
        ], new Response(status: $status));
    }

    /**
     * @return list<array{code: string, label: string}>
     */
    private function localeTabs(): array
    {
        return array_map(
            fn (string $locale): array => ['code' => $locale, 'label' => $this->translator->trans('editor.locale.'.$locale, [], 'cms')],
            $this->locales->all(),
        );
    }
}
