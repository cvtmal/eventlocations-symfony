<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Portal\Security\SubscriptionCancelToken;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Command\CancelSubscription;
use App\Venues\Exception\SubscriptionNotFound;
use App\Venues\Exception\SubscriptionRefused;
use App\Venues\ReadModel\SubscriptionTermQuery;
use App\Venues\ValueObject\TermDay;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The reminder mail's "Nicht verlängern" (G9): whoever holds the link may cancel the renewal of that one term, with no
 * login. A GET only shows the question, so a mail gateway that fetches the link changes nothing; the POST carries the
 * stateless `submit` token and answers with a redirect to the same page, which then says the term is cancelled. The
 * tier runs to the term's last day either way. A link of a term whose successor is already paid only says so.
 *
 * A dead link (bad signature, unknown term, a term that has ended) answers 410 in place, like the password link.
 */
final class CancelSubscriptionController extends AbstractController
{
    private const string TOKEN = 'submit';

    public function __construct(
        private readonly SubscriptionCancelToken $tokens,
        private readonly SubscriptionTermQuery $terms,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/konto/abo-kuendigen/{token}', name: 'portal_subscription_cancel', requirements: ['token' => SubscriptionCancelToken::REQUIREMENT], methods: ['GET', 'POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(Request $request, string $token): Response
    {
        $id = $this->tokens->idFrom($token);
        $term = null === $id ? null : $this->terms->byId($id);
        if (null === $term || $term->hasEndedBefore(TermDay::of($this->clock->now()))) {
            return $this->gone();
        }
        // An old mail's link after the next term was paid: cancelling this one would stop nothing.
        if ($term->paidUntil > $term->endsOn) {
            return $this->render('portal/subscription/cancel.html.twig', ['term' => $term, 'failed' => false, 'renewed' => true]);
        }

        if ($request->isMethod('POST') && !$term->isCancelled()) {
            $csrf = $request->request->get('_token');
            if (!\is_string($csrf) || !$this->isCsrfTokenValid(self::TOKEN, $csrf)) {
                return $this->render('portal/subscription/cancel.html.twig', ['term' => $term, 'failed' => true], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
            }
            try {
                $this->commandBus->dispatch(new CancelSubscription($term->venueNumber, $term->id));
            } catch (HandlerFailedException $failure) {
                // The term ended (midnight) or went with its venue between the check above and the lock.
                if ([] === $failure->getWrappedExceptions(SubscriptionRefused::class) && [] === $failure->getWrappedExceptions(SubscriptionNotFound::class)) {
                    throw $failure;
                }

                return $this->gone();
            }

            return $this->redirectToRoute('portal_subscription_cancel', ['token' => $token], Response::HTTP_SEE_OTHER);
        }

        return $this->render('portal/subscription/cancel.html.twig', ['term' => $term, 'failed' => false]);
    }

    private function gone(): Response
    {
        return $this->render('portal/security/notice.html.twig', [
            'title' => 'subscription.cancel.invalid.title',
            'text' => 'subscription.cancel.invalid.text',
            'link' => 'subscription.cancel.invalid.link',
            'link_route' => 'portal_login',
        ], new Response(status: Response::HTTP_GONE));
    }
}
