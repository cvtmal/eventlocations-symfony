<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\Admin\Exception\AdminUserRuleViolation;
use App\Admin\Extension\AuditLogNavigationItem;
use App\Admin\Extension\NavigationSection;
use App\Admin\Extension\OverviewNavigationItem;
use App\Admin\Extension\UsersNavigationItem;
use App\Admin\ValueObject\AdminRole;
use App\Admin\ValueObject\AuditActorType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * Keys built at runtime are invisible to `debug:translation`, so they are listed here.
 */
final class AdminTranslationKeysTest extends KernelTestCase
{
    public function test_runtime_keys_exist_in_the_catalogue(): void
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $catalogue = $translator->getCatalogue('de');

        $keys = [
            'login.error.invalid', 'login.error.disabled', 'login.error.throttled', 'totp.error.invalid',
            'flash.session_expired', 'flash.totp_enrolled', 'flash.user_created', 'flash.role_changed', 'flash.password_changed',
            'flash.user_deactivated', 'flash.user_reactivated', 'flash.totp_reset', 'flash.action_failed',
            'error.unauthenticated', 'error.forbidden', 'error.totp_required',
            (new OverviewNavigationItem())->label()->trans(new KeyEcho()),
            (new UsersNavigationItem())->label()->trans(new KeyEcho()),
            (new AuditLogNavigationItem())->label()->trans(new KeyEcho()),
        ];
        foreach (AdminRole::cases() as $role) {
            $keys[] = $role->label()->getMessage();
        }
        foreach (NavigationSection::cases() as $section) {
            $keys[] = $section->label()->getMessage();
        }
        foreach (AdminUserRuleViolation::all() as $violation) {
            $keys[] = $violation->translationKey();
        }
        foreach ([AuditActorType::Console, AuditActorType::System, AuditActorType::PortalUser] as $actor) {
            $keys[] = 'audit.actor.'.$actor->value;
        }

        foreach ($keys as $key) {
            self::assertTrue($catalogue->defines($key, 'admin+intl-icu') || $catalogue->defines($key, 'admin'), $key);
        }
    }
}
