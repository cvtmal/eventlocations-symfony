<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Admin\Form\ConfirmActionType;
use App\Crm\Form\CompanyType;
use App\Crm\Form\ContactType;
use App\Crm\Form\EmailTemplateType;
use App\Crm\Form\LinkVenueType;
use App\Crm\Form\TaskType;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfToken;

/**
 * Every CRM form that writes: a bad token is refused by the CSRF check itself. Without the check `_token`
 * would be an extra field and the form invalid for that reason, so the application tests alone cannot tell.
 */
final class CrmFormsCsrfTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{class-string<FormTypeInterface>, array<string, mixed>}>
     */
    public static function forms(): iterable
    {
        yield 'company' => [CompanyType::class, ['owners' => []]];
        yield 'contact' => [ContactType::class, ['owners' => [], 'company_url' => '/admin/api/crm/companies']];
        yield 'link venue' => [LinkVenueType::class, ['venue_url' => '/admin/api/crm/venues']];
        yield 'task' => [TaskType::class, ['owners' => []]];
        yield 'email template' => [EmailTemplateType::class, []];
        yield 'confirmation' => [ConfirmActionType::class, []];
    }

    /**
     * @param class-string<FormTypeInterface> $type
     * @param array<string, mixed>            $options
     */
    #[DataProvider('forms')]
    public function test_a_bad_token_is_the_csrf_error(string $type, array $options): void
    {
        self::assertTrue($this->refused($type, $options, ['_token' => 'wrong']));
        self::assertTrue($this->refused($type, $options, []));
        self::assertFalse($this->refused($type, $options, ['_token' => 'csrf-token']));
    }

    /**
     * @param class-string<FormTypeInterface> $type
     * @param array<string, mixed>            $options
     * @param array<string, string>           $token
     */
    private function refused(string $type, array $options, array $token): bool
    {
        $requests = self::getContainer()->get(RequestStack::class);
        $forms = self::getContainer()->get(FormFactoryInterface::class);
        self::assertInstanceOf(RequestStack::class, $requests);
        self::assertInstanceOf(FormFactoryInterface::class, $forms);
        $requests->push(Request::create('/admin/crm', 'POST', server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']));
        $form = $forms->create($type, null, $options);

        $form->submit($token);

        foreach ($form->getErrors() as $error) {
            if ($error instanceof FormError && $error->getCause() instanceof CsrfToken) {
                return true;
            }
        }

        return false;
    }
}
