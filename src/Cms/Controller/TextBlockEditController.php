<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Admin\ReadModel\AuditLogQuery;
use App\Cms\Command\SaveTextBlock;
use App\Cms\Exception\ContentTooLong;
use App\Cms\Exception\StaleContentBlocks;
use App\Cms\Exception\UnknownContentBlock;
use App\Cms\Form\ContentChanges;
use App\Cms\Form\TextBlockData;
use App\Cms\Form\TextBlockType;
use App\Cms\ReadModel\ContentBlockStatusQuery;
use App\Cms\ValueObject\CmsFlash;
use App\Cms\ValueObject\TextBlockKey;
use App\Shared\Extension\ContentBlockDefinition;
use App\Shared\Extension\ContentBlockPageDefault;
use App\Shared\Extension\ContentBlockRegistry;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\LocalizedRoutes;
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
 * One declared text block's Markdown per locale (#26), the same shape as `LandingPageEditController`: PRG
 * with a 303, no command for unchanged input (P9), 409 for a stale version, 422 over the block's limit. Only
 * a declared block is editable; any other key is a 404.
 */
#[IsGranted('ROLE_EDITOR')]
final class TextBlockEditController extends AbstractController
{
    public function __construct(
        private readonly ContentBlockRegistry $blocks,
        private readonly ContentBlockStatusQuery $status,
        private readonly AuditLogQuery $auditLog,
        private readonly MessageBusInterface $commandBus,
        private readonly TranslatorInterface $translator,
        private readonly EnabledLocale $locales,
    ) {
    }

    #[Route('/admin/textbausteine/{key}', name: 'admin_text_blocks_edit', requirements: ['key' => TextBlockKey::REQUIREMENT], methods: ['GET', 'POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(string $key, Request $request): Response
    {
        $definition = $this->blocks->get($key) ?? throw $this->createNotFoundException();
        $version = $this->status->versionOf([$key]);
        $bodies = $this->status->bodies([$key]);

        $data = TextBlockData::from($definition, $bodies, $this->locales->all(), $version);
        $form = $this->createForm(TextBlockType::class, $data, ['definition' => $definition]);
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            return $this->renderEditor($definition, $form, $request->isMethod('POST') ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        }
        if (!$form->isValid()) {
            return $this->renderEditor($definition, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $loaded = TextBlockData::from($definition, $bodies, $this->locales->all(), $version);
        $changes = ContentChanges::between($loaded->locales, $data->locales);
        $flashParameters = ['name' => $definition->label()];
        if ($changes->isEmpty()) {
            $this->addFlash('success', CmsFlash::message('blocks.flash.unchanged', $flashParameters));

            return $this->backTo($definition);
        }

        $bodiesByLocale = array_map(static fn (array $fields): ?string => $fields['body'] ?? null, $changes->texts);
        try {
            $this->commandBus->dispatch(new SaveTextBlock($key, $bodiesByLocale, (string) $data->version, $changes->locales));
        } catch (HandlerFailedException $failure) {
            if ([] !== $failure->getWrappedExceptions(UnknownContentBlock::class)) {
                throw $this->createNotFoundException();
            }
            if ([] !== $failure->getWrappedExceptions(StaleContentBlocks::class)) {
                $form->addError(new FormError($this->translator->trans('text.error.stale', [], 'cms')));

                return $this->renderEditor($definition, $form, Response::HTTP_CONFLICT, stale: true);
            }
            $tooLong = $failure->getWrappedExceptions(ContentTooLong::class);
            if ([] === $tooLong) {
                throw $failure;
            }
            $error = reset($tooLong);
            $form->get('locales')->get($error->locale)->get('body')
                ->addError(new FormError($this->translator->trans('cms.block.too_long', ['limit' => $error->maxLength], 'validators')));

            return $this->renderEditor($definition, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', CmsFlash::message('blocks.flash.saved', $flashParameters));

        return $this->backTo($definition);
    }

    private function backTo(ContentBlockDefinition $definition): Response
    {
        return $this->redirectToRoute('admin_text_blocks_edit', ['key' => $definition->key()], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function renderEditor(ContentBlockDefinition $definition, FormInterface $form, int $status = Response::HTTP_OK, bool $stale = false): Response
    {
        return $this->render('admin/cms/pages/block_edit.html.twig', [
            'definition' => $definition,
            'page_default' => $definition instanceof ContentBlockPageDefault ? $definition->pageDefault() : [],
            'form' => $form,
            'stale' => $stale,
            'locales' => array_map(
                fn (string $locale): array => ['code' => $locale, 'label' => $this->translator->trans('editor.locale.'.$locale, [], 'cms')],
                $this->locales->all(),
            ),
            'last_edit' => $this->auditLog->forSubject('content_block', $definition->key(), 1)[0] ?? null,
        ], new Response(status: $status));
    }
}
