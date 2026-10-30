<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Form\StyleguideSampleType;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tokens and every component of the back office, dev and test only. The sample form shows up twice,
 * the second time submitted with invalid values, so the error state is on the page.
 */
#[IsGranted('ROLE_EDITOR')]
final class StyleguideController extends AbstractController
{
    #[Route('/admin/_styleguide', name: 'admin_styleguide', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true], env: ['dev', 'test'])]
    public function __invoke(FormFactoryInterface $forms, TranslatorInterface $translator): Response
    {
        $form = $forms->createNamed('sample', StyleguideSampleType::class, [
            'name' => $translator->trans('ui.styleguide.sample.venue', [], 'admin'),
            'summary' => $translator->trans('ui.styleguide.sample.kpi_detail', [], 'admin'),
            'amount' => 4800.0,
            'town' => $translator->trans('ui.styleguide.form.option_meilen', [], 'admin'),
            'daylight' => 'yes',
            'setting' => ['lake'],
        ]);

        $invalid = $forms->createNamed('sample_invalid', StyleguideSampleType::class);
        $invalid->submit(['name' => '', 'summary' => str_repeat('x', StyleguideSampleType::SUMMARY_MAX + 1)]);
        $invalid->addError(new FormError($translator->trans('ui.styleguide.form.form_error', [], 'admin')));

        return $this->render('admin/styleguide/index.html.twig', [
            'form' => $form->createView(),
            'invalid_form' => $invalid->createView(),
        ]);
    }
}
