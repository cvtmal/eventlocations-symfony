<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\DealFactory;
use App\Factory\TaskFactory;
use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Meine Aufgaben" and "Überfällig" on the dashboard (`Main.dc.html`, D20).
 */
final class TaskWidgetsTest extends WebTestCase
{
    use AdminSession;
    use ClockSensitiveTrait;
    use Factories;

    private const int MINE = 2;
    private const int OVERDUE = 3;

    public function test_my_widget_lists_my_tasks_with_object_and_pill(): void
    {
        self::mockTime('2026-10-31 10:00:00 UTC');
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne(['name' => 'Gutshof Lindenhalde AG']);
        $contact = ContactFactory::createOne(['firstName' => 'Laura', 'lastName' => 'Meier']);
        TaskFactory::createOne(['title' => 'Offerte senden', 'assignee' => $me, 'company' => $company, 'dueOn' => new \DateTimeImmutable('2026-10-30')]);
        TaskFactory::createOne(['title' => 'Rückruf', 'assignee' => $me, 'contact' => $contact, 'dueOn' => new \DateTimeImmutable('2026-10-31')]);
        TaskFactory::createOne(['title' => 'Ablage', 'assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-11-03')]);
        TaskFactory::createOne(['title' => 'Fremde', 'assignee' => AdminUserFactory::createOne(), 'dueOn' => new \DateTimeImmutable('2026-11-04')]);

        $widget = $this->widget(self::MINE);

        self::assertSame('Meine Aufgaben', $widget->filter('h2')->text());
        self::assertSame(['Offerte senden', 'Rückruf', 'Ablage'], $this->titles($widget));
        self::assertSame(['Überfällig', 'Heute', 'Di, 3.11.'], $widget->filter('.pill')->each(static fn (Crawler $pill): string => $pill->text()));
        self::assertSame(['Firma · Gutshof Lindenhalde AG', 'Kontakt · Laura Meier'], $widget->filter('.crm-list__detail')->each(static fn (Crawler $detail): string => $detail->text()));
        self::assertSame(
            ['/admin/crm/firmen/'.$company->id(), '/admin/crm/kontakte/'.$contact->id()],
            $widget->filter('.crm-list__detail a')->each(static fn (Crawler $link): ?string => $link->attr('href')),
        );
        self::assertCount(1, $widget->filter('.task-widget__row')->last()->filter('.crm-task__text > *'), 'A task on no object has no second line.');
    }

    public function test_a_task_on_a_deal_names_the_deal_and_its_company(): void
    {
        self::mockTime('2026-10-31 10:00:00 UTC');
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne(['company' => CompanyFactory::createOne(['name' => 'Seehotel Bellerive'])]);
        TaskFactory::createOne(['title' => 'Verlängerung besprechen', 'assignee' => $me, 'deal' => $deal, 'dueOn' => new \DateTimeImmutable('2026-11-03')]);

        $detail = $this->widget(self::MINE)->filter('.crm-list__detail');

        self::assertSame('Deal · Seehotel Bellerive', $detail->text());
        self::assertSame('/admin/crm/deals/'.$deal->id(), $detail->filter('a')->attr('href'));
    }

    public function test_the_overdue_widget_holds_anyones_tasks_and_names_who_has_them(): void
    {
        self::mockTime('2026-10-31 10:00:00 UTC');
        $this->logInAs(AdminRole::Editor, 'Andrea Keller');
        $other = AdminUserFactory::createOne(['displayName' => 'Beat Frei']);
        TaskFactory::createOne(['title' => 'Vertrag', 'assignee' => $other, 'company' => CompanyFactory::createOne(['name' => 'Seehotel']), 'dueOn' => new \DateTimeImmutable('2026-10-29')]);
        TaskFactory::createOne(['title' => 'Ohne Objekt', 'assignee' => $other, 'dueOn' => new \DateTimeImmutable('2026-10-30')]);
        TaskFactory::createOne(['title' => 'Heutige', 'assignee' => $other, 'dueOn' => new \DateTimeImmutable('2026-10-31')]);

        $widget = $this->widget(self::OVERDUE);

        self::assertSame('Überfällig', $widget->filter('h2')->text());
        self::assertSame(['Vertrag', 'Ohne Objekt'], $this->titles($widget));
        self::assertSame(['Firma · Seehotel · Beat Frei', 'Beat Frei'], $widget->filter('.crm-list__detail')->each(static fn (Crawler $detail): string => $detail->text()));
    }

    public function test_the_links_open_the_matching_tab(): void
    {
        $this->logInAs(AdminRole::Editor);

        self::assertSame('Alle Aufgaben', $this->widget(self::MINE)->filter('.widget__head-link a')->text());
        self::assertSame('/admin/crm/aufgaben?view=meine', $this->widget(self::MINE)->filter('.widget__head-link a')->attr('href'));
        self::assertSame('/admin/crm/aufgaben?view=ueberfaellig', $this->widget(self::OVERDUE)->filter('.widget__head-link a')->attr('href'));
    }

    public function test_a_widget_shows_five_and_says_how_many_there_are(): void
    {
        self::mockTime('2026-10-31 10:00:00 UTC');
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        TaskFactory::createMany(6, ['assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-10-30')]);

        foreach ([self::MINE, self::OVERDUE] as $index) {
            $widget = $this->widget($index);

            self::assertCount(5, $widget->filter('.task-widget__row'));
            self::assertSame('Alle 6 Aufgaben', $widget->filter('.widget__head-link a')->text());
        }
    }

    public function test_a_toggle_comes_back_to_the_dashboard_without_the_task(): void
    {
        self::mockTime('2026-10-31 10:00:00 UTC');
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        $task = TaskFactory::createOne(['title' => 'Offerte senden', 'assignee' => $me, 'dueOn' => new \DateTimeImmutable('2026-11-03')]);

        $widget = $this->widget(self::MINE);
        self::assertSame('/admin/', $widget->filter('input[name="return_to"]')->attr('value'), '`ReturnPath::safe()` refuses `/admin`.');
        self::assertSame('/admin/crm/aufgaben/'.$task->id().'/status', $widget->filter('form')->attr('action'));

        $this->client->request('POST', '/admin/crm/aufgaben/'.$task->id().'/status', [
            '_token' => 'csrf-token',
            'done' => '1',
            'return_to' => $widget->filter('input[name="return_to"]')->attr('value'),
        ], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/admin/');
        $this->client->followRedirect();
        self::assertResponseRedirects('/admin');
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSame('Keine offenen Aufgaben.', $crawler->filter('.widget')->eq(self::MINE)->filter('.card__body')->text());
        self::assertSelectorTextContains('.toast', 'Aufgabe erledigt.');
    }

    public function test_a_title_is_escaped(): void
    {
        ['user' => $me] = $this->logInAs(AdminRole::Editor);
        TaskFactory::createOne(['title' => '<b>Fett</b>', 'assignee' => $me]);

        $this->widget(self::MINE);

        self::assertStringContainsString('&lt;b&gt;Fett&lt;/b&gt;', (string) $this->client->getResponse()->getContent());
    }

    private function widget(int $index): Crawler
    {
        return $this->client->request('GET', '/admin')->filter('.widget')->eq($index);
    }

    /**
     * @return list<string>
     */
    private function titles(Crawler $widget): array
    {
        return $widget->filter('.crm-task__title')->each(static fn (Crawler $title): string => $title->text());
    }
}
