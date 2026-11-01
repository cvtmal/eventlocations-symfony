<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Factory\CompanyFactory;
use App\Factory\DealFactory;
use App\Factory\VenueFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\AdminSession;
use App\Venues\ValueObject\Tier;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The board's one write (D17-a, D17-b): `expected` is the stage the card showed, the same target twice is a no-op with
 * no audit row, the first mover wins.
 */
final class DealStageApiTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    public function test_an_editor_moves_a_deal(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne();

        $this->move($deal->id(), ['stage' => 'offer', 'expected' => 'new']);

        self::assertResponseIsSuccessful();
        self::assertSame(['stage' => 'offer', 'closedOn' => null, 'tierOffer' => null], $this->answer());
        self::assertSame('offer', $this->connection->fetchOne('SELECT stage_code FROM crm_deals WHERE id = ?', [$deal->id()]));
        self::assertSame(1, $this->auditRows($deal->id()));
    }

    public function test_a_won_move_answers_its_day(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::new()->inStage('negotiation')->create();

        $this->move($deal->id(), ['stage' => 'won', 'expected' => 'negotiation']);

        self::assertResponseIsSuccessful();
        $answer = $this->answer();
        self::assertSame('won', $answer['stage']);
        self::assertIsString($answer['closedOn']);
        self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}\z/', $answer['closedOn']);
        self::assertNull($answer['tierOffer']);
    }

    public function test_a_won_move_answers_the_tier_offer_with_its_form(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick', 'tier' => Tier::Basic]);
        $company = CompanyFactory::new()->withVenues($venue)->create();
        $deal = DealFactory::new()->inStage('negotiation')->create(['company' => $company, 'venue' => $venue, 'tier' => Tier::Premium]);

        $this->move($deal->id(), ['stage' => 'won', 'expected' => 'negotiation']);

        self::assertResponseIsSuccessful();
        self::assertSame([
            'action' => '/admin/crm/deals/'.$deal->id().'/tier',
            'token' => 'csrf-token',
            'expected' => 'basic',
            'text' => '«Schloss Seeblick» hat heute den Tier Standard. Der Deal verkauft Premium.',
        ], $this->answer()['tierOffer']);
    }

    public function test_the_same_target_twice_is_a_no_op_without_a_second_row(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne();

        $this->move($deal->id(), ['stage' => 'offer', 'expected' => 'new']);
        self::assertResponseIsSuccessful();

        // The second person's card still says `new`: nothing to do, not a conflict.
        $this->move($deal->id(), ['stage' => 'offer', 'expected' => 'new']);

        self::assertResponseIsSuccessful();
        self::assertSame(['stage' => 'offer', 'closedOn' => null, 'tierOffer' => null], $this->answer());
        self::assertSame(1, $this->auditRows($deal->id()));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_activity_deals WHERE deal_id = ?', [$deal->id()]));
    }

    public function test_another_stage_than_the_expected_one_is_a_409(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::new()->inStage('offer')->create();

        $this->move($deal->id(), ['stage' => 'negotiation', 'expected' => 'new']);

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame('offer', $this->connection->fetchOne('SELECT stage_code FROM crm_deals WHERE id = ?', [$deal->id()]));
        self::assertSame(0, $this->auditRows($deal->id()));
    }

    public function test_an_unknown_stage_is_a_422(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne();

        $this->move($deal->id(), ['stage' => 'paused', 'expected' => 'new']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['stage'], array_keys($this->errors()));
    }

    public function test_lost_without_a_reason_is_a_422(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne();

        $this->move($deal->id(), ['stage' => 'lost', 'expected' => 'new']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['lostReason' => ['Bitte einen Grund für den Verlust wählen.']], $this->errors());
        self::assertSame('new', $this->connection->fetchOne('SELECT stage_code FROM crm_deals WHERE id = ?', [$deal->id()]));
        self::assertSame(0, $this->auditRows($deal->id()));
    }

    public function test_an_unknown_reason_is_a_422(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne();

        $this->move($deal->id(), ['stage' => 'lost', 'expected' => 'new', 'lostReason' => 'weather']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['lostReason'], array_keys($this->errors()));
    }

    public function test_lost_with_a_reason_stores_it(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne();

        $this->move($deal->id(), ['stage' => 'lost', 'expected' => 'new', 'lostReason' => 'budget']);

        self::assertResponseIsSuccessful();
        self::assertSame('budget', $this->connection->fetchOne('SELECT lost_reason FROM crm_deals WHERE id = ?', [$deal->id()]));
    }

    public function test_an_unknown_deal_is_a_json_404(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->move(EntityId::generate(), ['stage' => 'offer', 'expected' => 'new']);

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function test_malformed_json_is_a_json_422(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne();
        $token = $this->token();

        $this->client->request('PATCH', $this->url($deal->id()), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: "{\"stage\": \"a\xFFb\"}");

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function test_a_missing_token_is_refused(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne();

        $this->client->request('PATCH', $this->url($deal->id()), server: ['CONTENT_TYPE' => 'application/json'], content: '{"stage": "offer", "expected": "new"}');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('new', $this->connection->fetchOne('SELECT stage_code FROM crm_deals WHERE id = ?', [$deal->id()]));
    }

    public function test_an_anonymous_visitor_gets_a_json_401(): void
    {
        $deal = DealFactory::createOne();

        $this->client->request('PATCH', $this->url($deal->id()), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'any'], content: '{}');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    private function url(string $id): string
    {
        return '/admin/api/crm/deals/'.$id.'/stage';
    }

    private function token(): string
    {
        return (string) $this->client->request('GET', '/admin')->filter('meta[name="csrf-token"]')->attr('content');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function move(string $id, array $body): void
    {
        $token = $this->token();
        $this->client->request('PATCH', $this->url($id), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: (string) json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(): array
    {
        /** @var array<string, mixed> $answer */
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $answer;
    }

    /**
     * @return array<string, list<string>>
     */
    private function errors(): array
    {
        $errors = $this->answer()['errors'] ?? null;
        self::assertIsArray($errors);

        /* @var array<string, list<string>> $errors */
        return $errors;
    }

    private function auditRows(string $dealId): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM admin_audit_log WHERE subject_type = ? AND subject_id = ?', ['deal', $dealId]);
    }
}
