<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Portal\Command\ChangePortalPassword;
use App\Portal\Command\UpdatePortalProfile;
use App\Portal\Entity\PortalUser;
use App\Tests\Support\Portal\PortalSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class ProfileTest extends WebTestCase
{
    use Factories;
    use PortalSession;

    private const string NEW_PASSWORD = 'a-brand-new-password';

    public function test_it_shows_the_stored_values_and_the_address_read_only(): void
    {
        $this->logInAsManager($this->createManager(attributes: ['firstName' => 'Nora', 'lastName' => 'Meier', 'email' => 'nora@example.ch']));

        $crawler = $this->client->request('GET', '/konto/profil');

        self::assertResponseIsSuccessful();
        self::assertSame('Nora', $crawler->filter('input[name="profile[firstName]"]')->attr('value'));
        self::assertSame('Meier', $crawler->filter('input[name="profile[lastName]"]')->attr('value'));
        self::assertSame('nora@example.ch', $crawler->filter('#profile_email')->attr('value'));
        self::assertNull($crawler->filter('#profile_email')->attr('name'));
        self::assertNotNull($crawler->filter('#profile_email')->attr('readonly'));
        self::assertCount(1, $crawler->filter('a.sidebar__item--active[href="/konto/profil"][aria-current="page"]'));
    }

    public function test_a_change_is_saved_shown_and_audited_as_the_manager(): void
    {
        $manager = $this->logInAsManager();

        $this->saveProfile(['profile[firstName]' => 'Nora', 'profile[lastName]' => 'Meier', 'profile[phone]' => '044 123 45 67']);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/konto/profil');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Deine Angaben sind gespeichert.', $crawler->text());
        self::assertSame('Nora Meier', trim($crawler->filter('.sidebar__name')->text()));

        $user = $this->reload($manager);
        self::assertSame('Nora', $user->firstName());
        self::assertSame('Meier', $user->lastName());
        self::assertSame('044 123 45 67', $user->phone());

        $row = $this->connection->fetchAssociative('SELECT actor_type, actor_id, portal_user_id, subject_type, subject_id FROM admin_audit_log WHERE command = ?', [UpdatePortalProfile::class]);
        self::assertIsArray($row);
        self::assertSame('portal_user', $row['actor_type']);
        self::assertNull($row['actor_id']);
        self::assertSame($manager->id(), $row['portal_user_id']);
        self::assertSame('portal_user', $row['subject_type']);
        self::assertSame($manager->id(), $row['subject_id']);
    }

    public function test_a_cleared_phone_is_stored_as_null(): void
    {
        $manager = $this->logInAsManager();
        $this->saveProfile(['profile[phone]' => '044 123 45 67']);
        self::assertSame('044 123 45 67', $this->reload($manager)->phone());

        $this->saveProfile(['profile[phone]' => '']);

        self::assertResponseStatusCodeSame(303);
        self::assertNull($this->reload($manager)->phone());
    }

    public function test_a_crafted_email_field_changes_nothing(): void
    {
        $manager = $this->logInAsManager($this->createManager(attributes: ['email' => 'nora@example.ch']));
        $this->client->request('GET', '/konto/profil');

        $this->client->request('POST', '/konto/profil', ['profile' => [
            'firstName' => 'Nora',
            'lastName' => 'Meier',
            'phone' => '',
            'email' => 'stolen@example.ch',
            '_token' => 'csrf-token',
        ]]);

        self::assertSame('nora@example.ch', $this->reload($manager)->email());
    }

    public function test_a_blank_name_is_refused(): void
    {
        $manager = $this->logInAsManager($this->createManager(attributes: ['firstName' => 'Nora']));

        $this->saveProfile(['profile[firstName]' => '   ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Nora', $this->reload($manager)->firstName());
        self::assertSelectorExists('#profile_firstName[aria-invalid="true"]');
    }

    public function test_a_nul_is_refused(): void
    {
        $manager = $this->logInAsManager($this->createManager(attributes: ['lastName' => 'Meier']));

        $this->saveProfile(['profile[lastName]' => "Mei\0er"]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Meier', $this->reload($manager)->lastName());
    }

    public function test_a_phone_longer_than_the_column_is_refused(): void
    {
        $this->logInAsManager();

        $this->saveProfile(['profile[phone]' => str_repeat('1', 41)]);

        self::assertResponseStatusCodeSame(422);
    }

    public function test_a_wrong_current_password_is_refused(): void
    {
        $manager = $this->logInAsManager();
        $hash = $manager->passwordHash();

        $this->changePassword('not-my-password');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#password_currentPassword[aria-invalid="true"]');
        self::assertSame($hash, $this->reload($manager)->passwordHash());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM admin_audit_log WHERE command = ?', [ChangePortalPassword::class]));
    }

    public function test_two_different_new_passwords_are_refused(): void
    {
        $manager = $this->logInAsManager();
        $hash = $manager->passwordHash();

        $this->changePassword(self::MANAGER_PASSWORD, second: 'something-else-entirely');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Die beiden Passwörter stimmen nicht überein.');
        self::assertSame($hash, $this->reload($manager)->passwordHash());
    }

    public function test_a_short_new_password_is_refused(): void
    {
        $this->logInAsManager();

        $this->changePassword(self::MANAGER_PASSWORD, 'short');

        self::assertResponseStatusCodeSame(422);
    }

    public function test_a_password_change_ends_the_session_and_the_new_password_logs_in(): void
    {
        $manager = $this->logInAsManager();

        $this->changePassword(self::MANAGER_PASSWORD);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/konto/anmelden');

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Dein Passwort ist geändert.', $crawler->text());
        self::assertCount(0, $crawler->filter('.sidebar'));

        $this->client->request('GET', '/konto/profil');
        self::assertResponseRedirects('/konto/anmelden');

        $this->submitManagerLogin($manager->email());
        self::assertResponseRedirects('/konto/anmelden');
        $this->client->request('GET', '/konto/profil');
        self::assertResponseRedirects('/konto/anmelden');

        $this->submitManagerLogin($manager->email(), self::NEW_PASSWORD);
        self::assertResponseRedirects('/konto/profil');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function test_a_password_change_is_audited_and_the_summary_holds_no_secret(): void
    {
        $manager = $this->logInAsManager();

        $this->changePassword(self::MANAGER_PASSWORD);

        $row = $this->connection->fetchAssociative('SELECT actor_type, portal_user_id, changes FROM admin_audit_log WHERE command = ?', [ChangePortalPassword::class]);
        self::assertIsArray($row);
        self::assertSame('portal_user', $row['actor_type']);
        self::assertSame($manager->id(), $row['portal_user_id']);
        self::assertIsString($row['changes']);
        self::assertSame(['password' => 'changed'], json_decode($row['changes'], true));
        self::assertStringNotContainsString(self::NEW_PASSWORD, $row['changes']);
        self::assertStringNotContainsString(self::MANAGER_PASSWORD, $row['changes']);
    }

    /**
     * @param array<string, string> $fields
     */
    private function saveProfile(array $fields): void
    {
        $this->client->request('GET', '/konto/profil');
        $this->client->submitForm('Speichern', $fields);
    }

    private function changePassword(string $current, string $new = self::NEW_PASSWORD, ?string $second = null): void
    {
        $this->client->request('GET', '/konto/profil');
        $this->client->submitForm('Passwort speichern', [
            'password[currentPassword]' => $current,
            'password[plainPassword][first]' => $new,
            'password[plainPassword][second]' => $second ?? $new,
        ]);
    }

    private function reload(PortalUser $manager): PortalUser
    {
        $em = $this->portalEntityManager();
        $em->clear();
        $user = $em->find(PortalUser::class, $manager->id());
        self::assertInstanceOf(PortalUser::class, $user);

        return $user;
    }
}
