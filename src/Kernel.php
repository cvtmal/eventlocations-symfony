<?php

declare(strict_types=1);

namespace App;

use App\Shared\Security\ReviewMode;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * review-demo: APP_REVIEW_MODE switches 2FA off and lets `admin` log in, so the app refuses to start with it on
     * anywhere but the review stack (ReviewMode::problems()), before the container or a request exists.
     */
    public function boot(): void
    {
        // $_ENV first, as the container's env processor reads it.
        $problems = ReviewMode::problems($_ENV + $_SERVER, $this->environment);
        if ([] !== $problems) {
            throw new \LogicException('Refusing to boot: '.implode(' ', $problems));
        }

        parent::boot();
    }
}
