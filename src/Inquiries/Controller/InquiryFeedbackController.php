<?php

declare(strict_types=1);

namespace App\Inquiries\Controller;

use App\Inquiries\Command\AnswerInquiryFeedback;
use App\Inquiries\Command\OptOutOfInquiryFeedback;
use App\Inquiries\FeedbackToken;
use App\Inquiries\Form\FeedbackData;
use App\Inquiries\Form\FeedbackType;
use App\Inquiries\ReadModel\FeedbackState;
use App\Inquiries\ReadModel\InquiryFeedbackQuery;
use App\Inquiries\ValueObject\FeedbackOutcome;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The links of the feedback mail (G16). `/rueckmeldung/{token}` shows the two questions (filled in when the guest
 * answered before) and stores the answer on POST; `/rueckmeldung/{token}/abmelden` asks on GET and stops the mails to
 * the address on POST, which is also RFC 8058's one-click target, so it has no CSRF token: the signed URL is the
 * proof. A GET never writes, because mail scanners open links.
 *
 * Private like the CRM opt-out page: `no-store`, `noindex`, no stats beacon, no canonical, no session. The opt-out page
 * sends no referrer; the answer page sends it to its own origin only (`same-origin`): with `no-referrer` a browser
 * without `Sec-Fetch-Site` posts `Origin: null` and the stateless CSRF check would refuse every answer.
 * Any method and any segment land here, so a near miss gets this page and never the cacheable error page.
 */
final class InquiryFeedbackController extends AbstractController
{
    private const array HEADERS = ['X-Robots-Tag' => 'noindex, nofollow'];

    public function __construct(
        private readonly FeedbackToken $tokens,
        private readonly InquiryFeedbackQuery $feedback,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route(path: ['de' => '/rueckmeldung/{token}'], name: 'inquiry_feedback', requirements: ['token' => '[^/]+'])]
    public function answer(Request $request, string $token): Response
    {
        if (null !== ($refused = $this->refuseMethod($request, $token))) {
            return $refused;
        }
        $state = $this->state($token);
        if (null === $state || !$state->mailed) {
            return $this->page('unknown', Response::HTTP_NOT_FOUND, $token);
        }

        $data = new FeedbackData();
        $data->venueReplied = $state->venueReplied;
        $data->outcome = $state->outcome;
        $form = $this->createForm(FeedbackType::class, $data, [
            'action' => $this->generateUrl('inquiry_feedback', ['token' => $token]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            \assert(null !== $data->venueReplied && $data->outcome instanceof FeedbackOutcome);
            $this->commandBus->dispatch(new AnswerInquiryFeedback($state->inquiryId, $data->venueReplied, $data->outcome));

            return $this->page('done', Response::HTTP_OK, $token, $state, referrer: 'same-origin');
        }

        $status = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        return $this->page('form', $status, $token, $state, $form, 'same-origin');
    }

    #[Route(path: ['de' => '/rueckmeldung/{token}/abmelden'], name: 'inquiry_feedback_opt_out', requirements: ['token' => '[^/]+'])]
    public function optOut(Request $request, string $token): Response
    {
        if (null !== ($refused = $this->refuseMethod($request, $token))) {
            return $refused;
        }
        $state = $this->state($token);
        if (null === $state) {
            return $this->page('unknown', Response::HTTP_NOT_FOUND, $token);
        }

        if ($request->isMethod('POST')) {
            if (!$state->optedOut) {
                $this->commandBus->dispatch(new OptOutOfInquiryFeedback($state->inquiryId));
            }

            return $this->page('opt_out_done', Response::HTTP_OK, $token, $state);
        }

        return $this->page($state->optedOut ? 'opt_out_done' : 'opt_out_ask', Response::HTTP_OK, $token, $state);
    }

    private function state(string $token): ?FeedbackState
    {
        $inquiryId = $this->tokens->inquiryIdFrom($token);

        return null === $inquiryId ? null : $this->feedback->forInquiry($inquiryId);
    }

    private function refuseMethod(Request $request, string $token): ?Response
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD') || $request->isMethod('POST')) {
            return null;
        }
        $response = $this->page('unknown', Response::HTTP_METHOD_NOT_ALLOWED, $token);
        $response->headers->set('Allow', 'GET, HEAD, POST');

        return $response;
    }

    private function page(string $state, int $status, string $token, ?FeedbackState $feedback = null, ?FormInterface $form = null, string $referrer = 'no-referrer'): Response
    {
        $response = $this->render('inquiries/feedback.html.twig', [
            'state' => $state,
            'token' => $token,
            'feedback' => $feedback,
            'form' => $form?->createView(),
        ], new Response(status: $status));
        $response->headers->add(self::HEADERS);
        $response->headers->set('Referrer-Policy', $referrer);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
