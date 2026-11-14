<?php

namespace HuseyinFiliz\Pickem\Tests\Integration;

use Carbon\Carbon;
use Flarum\Locale\LocaleManager;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use HuseyinFiliz\Pickem\Event;
use HuseyinFiliz\Pickem\Pick;
use HuseyinFiliz\Pickem\Season;
use HuseyinFiliz\Pickem\Team;
use HuseyinFiliz\Pickem\UserScore;
use HuseyinFiliz\Pickem\Week;
use PHPUnit\Framework\Attributes\Test;

class PickemApiTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('huseyinfiliz-pickem');
        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Team::class => [
                ['id' => 1, 'name' => 'Home', 'slug' => 'home'],
                ['id' => 2, 'name' => 'Away', 'slug' => 'away'],
            ],
            Event::class => [[
                'id' => 1, 'home_team_id' => 1, 'away_team_id' => 2,
                'match_date' => Carbon::now()->addDays(2),
                'cutoff_date' => Carbon::now()->addDay(),
                'allow_draw' => true,
            ]],
            'group_permission' => [
                ['permission' => 'pickem.view', 'group_id' => 2],
                ['permission' => 'pickem.makePicks', 'group_id' => 3],
            ],
        ]);
    }

    private function api(string $method, string $path, int $actor = 1, array $attributes = [])
    {
        return $this->send($this->request($method, '/api'.$path, [
            'authenticatedAs' => $actor,
            'json' => ['data' => ['attributes' => $attributes]],
        ]));
    }

    #[Test]
    public function eventsSerializeTeamsAndPaginate(): void
    {
        $response = $this->api('GET', '/pickem-events?page[limit]=1');
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['data'][0]['attributes']['canPick']);
        $this->assertSame('pickem-teams', $payload['data'][0]['relationships']['homeTeam']['data']['type']);
        $this->assertCount(2, $payload['included']);
        $this->assertSame(1, $payload['meta']['total']);
    }

    #[Test]
    public function adminCanImportMatchesAtomicallyInLocalTime(): void
    {
        $this->app();
        $this->app()->getContainer()->make(LocaleManager::class)->addTranslations('en', dirname(__DIR__, 2).'/resources/locale/en.yml');
        Season::create(['id' => 1, 'name' => 'Season', 'slug' => 'season']);
        Week::create(['id' => 1, 'name' => 'Week', 'season_id' => 1]);
        $data = [
            'seasonId' => 1,
            'weekId' => 1,
            'timezone' => 'Europe/Sofia',
            'csv' => "home_slug, away_slug, start_time\nhome, away, 2030-07-01 20:00\naway, home, 2030-07-02 00:00\nhome,away,2030-10-27 03:30",
        ];
        $this->assertSame(403, $this->api('POST', '/pickem-events/import', 2, $data)->getStatusCode());

        $response = $this->api('POST', '/pickem-events/import', 1, $data);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(3, json_decode((string) $response->getBody(), true)['count']);
        $event = Event::where('home_team_id', 1)->where('away_team_id', 2)->where('week_id', 1)->firstOrFail();
        $this->assertSame('2030-07-01 17:00:00', $event->match_date->format('Y-m-d H:i:s'));
        $this->assertSame('2030-07-01 16:59:00', $event->cutoff_date->format('Y-m-d H:i:s'));
        $this->assertTrue($event->allow_draw);
        $repeatedHour = Event::where('week_id', 1)->where('match_date', '2030-10-27 00:30:00')->firstOrFail();
        $this->assertSame('2030-10-27 00:29:00', $repeatedHour->cutoff_date->format('Y-m-d H:i:s'));

        $count = Event::count();
        $data['csv'] = "home,new-team,2030-01-01 12:00\nhome,Invalid_slug,2030-01-01 12:00\nhome,home,2030-01-01 12:00";
        $response = $this->api('POST', '/pickem-events/import', 1, $data);
        $this->assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Row 2', $body);
        $this->assertStringContainsString('Row 3', $body);
        $this->assertSame($count, Event::count());
        $this->assertFalse(Team::where('slug', 'new-team')->exists());
    }

    #[Test]
    public function importCreatesMissingTeamsAndReusesThem(): void
    {
        $this->app();
        Season::create(['id' => 1, 'name' => 'Season', 'slug' => 'season']);
        Week::create(['id' => 1, 'name' => 'Week', 'season_id' => 1]);
        $data = [
            'seasonId' => 1, 'weekId' => 1, 'timezone' => 'UTC',
            'csv' => "home,new-team,2030-01-01 12:00\nnew-team,another-team,2030-01-02 12:00\nnew-team,home,2030-01-03 12:00",
        ];
        $this->assertSame(403, $this->api('POST', '/pickem-events/import', 2, $data)->getStatusCode());
        $this->assertFalse(Team::where('slug', 'new-team')->exists());
        $response = $this->api('POST', '/pickem-events/import', 1, $data);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame(3, $payload['count']);
        $this->assertCount(2, $payload['teams']);
        $team = Team::where('slug', 'new-team')->firstOrFail();
        $other = Team::where('slug', 'another-team')->firstOrFail();
        $this->assertSame('new-team', $team->name);
        $this->assertNull($team->logo_path);
        $this->assertSame('another-team', $other->name);
        $this->assertNull($other->logo_path);
        $this->assertSame(1, Team::where('slug', 'new-team')->count());
        $this->assertSame(3, Event::where('home_team_id', $team->id)->orWhere('away_team_id', $team->id)->count());
        $this->assertSame('pickem-teams', $payload['teams'][0]['type']);
        $this->assertSame('new-team', $payload['teams'][0]['attributes']['name']);
    }

    #[Test]
    public function importRejectsInvalidInputAndMismatchedWeeks(): void
    {
        $this->app();
        Season::create(['id' => 1, 'name' => 'Season', 'slug' => 'season']);
        Week::create(['id' => 1, 'name' => 'Week', 'season_id' => 1]);
        $data = ['seasonId' => 1, 'weekId' => 1, 'timezone' => 'Europe/Sofia', 'csv' => 'home,away,2030-01-01 12:00'];
        foreach ([
            ['weekId' => 99],
            ['seasonId' => 99],
            ['timezone' => 'invalid-zone'],
            ['csv' => 'home,away,2030-02-30 12:00'],
            ['csv' => "home,away,2030-01-01 12:00\0"],
            ['csv' => 'home,away,0001-01-01 00:00'],
            ['csv' => 'home,away,2030-03-31 03:30'],
            ['csv' => 'home,away'],
            ['csv' => 'home,invalid_slug,2030-01-01 12:00'],
            ['csv' => 'home,,2030-01-01 12:00'],
            ['csv' => 'home,'.str_repeat('a', 101).',2030-01-01 12:00'],
            ['csv' => 'new-team,new-team,2030-01-01 12:00'],
            ['csv' => 'home_slug,away_slug,start_time'],
            ['csv' => str_repeat("home,away,2030-01-01 12:00\n", 201)],
        ] as $invalid) {
            $response = $this->api('POST', '/pickem-events/import', 1, array_replace($data, $invalid));
            $this->assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        }
        $this->assertSame(1, Event::count());
    }

    #[Test]
    public function memberCanPickAndListNestedIncludes(): void
    {
        $response = $this->api('POST', '/pickem-picks', 2, ['eventId' => 1, 'selectedOutcome' => 'home']);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $response = $this->api('GET', '/pickem-picks?include=event,event.homeTeam,event.awayTeam', 2);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertCount(1, $payload['data']);
        $this->assertContains('pickem-events', array_column($payload['included'], 'type'));
        $this->assertContains('users', array_column($payload['included'], 'type'));
    }

    #[Test]
    public function resultsScorePicksAndSendNotifications(): void
    {
        $this->api('POST', '/pickem-picks', 2, ['eventId' => 1, 'selectedOutcome' => 'home']);
        $response = $this->api('POST', '/pickem-events/1/result', 1, ['homeScore' => 2, 'awayScore' => 1]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertTrue(Pick::where('user_id', 2)->firstOrFail()->is_correct);
        $this->assertEquals(1, UserScore::where('user_id', 2)->firstOrFail()->total_points);
        $this->assertTrue($this->database()->table('notifications')->where('type', 'pickem_event_result')->where('user_id', 2)->exists());
        $this->assertSame(422, $this->api('POST', '/pickem-picks', 2, ['eventId' => 1, 'selectedOutcome' => 'away'])->getStatusCode());
    }

    #[Test]
    public function membersCannotManageAndInvalidMatchesAreRejected(): void
    {
        $this->assertSame(403, $this->api('POST', '/pickem-teams', 2, ['name' => 'Denied'])->getStatusCode());
        $response = $this->api('POST', '/pickem-events', 1, [
            'homeTeamId' => 1, 'awayTeamId' => 1,
            'matchDate' => Carbon::now()->addDays(2)->toIso8601String(),
            'cutoffDate' => Carbon::now()->addDay()->toIso8601String(),
        ]);
        $this->assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function partialMatchUpdatesPreserveValidationContext(): void
    {
        $response = $this->api('PATCH', '/pickem-events/1', 1, ['allowDraw' => false]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['data']['attributes']['allowDraw']);
        $this->assertSame(422, $this->api('POST', '/pickem-picks', 2, ['eventId' => 1, 'selectedOutcome' => 'draw'])->getStatusCode());
    }

    #[Test]
    public function recalculateToolAndDeletingMatchesRefreshScores(): void
    {
        $this->api('POST', '/pickem-picks', 2, ['eventId' => 1, 'selectedOutcome' => 'home']);
        $this->api('POST', '/pickem-events/1/result', 1, ['homeScore' => 2, 'awayScore' => 1]);
        $response = $this->api('POST', '/pickem/recalculate-all-scores');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(1, json_decode((string) $response->getBody(), true)['jobCount']);
        $this->assertSame(204, $this->api('DELETE', '/pickem-events/1')->getStatusCode());
        $this->assertSame(0, Pick::where('user_id', 2)->count());
        $this->assertEquals(0, UserScore::where('user_id', 2)->firstOrFail()->total_points);
    }

    #[Test]
    public function deletingAnUnfinishedMatchWithPicksRefreshesScores(): void
    {
        $this->assertSame(201, $this->api('POST', '/pickem-picks', 2, [
            'eventId' => 1, 'selectedOutcome' => 'home', 'predictedHomeScore' => 2, 'predictedAwayScore' => 0,
        ])->getStatusCode());

        $response = $this->api('DELETE', '/pickem-events/1');
        $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());
        $this->assertFalse(Event::where('id', 1)->exists());
        $this->assertSame(0, Pick::where('user_id', 2)->count());
        $this->assertEquals(0, UserScore::where('user_id', 2)->firstOrFail()->total_points);
    }

    #[Test]
    public function exactScoresCanBeEditedClearedAndValidated(): void
    {
        $attributes = ['eventId' => 1, 'selectedOutcome' => 'home', 'predictedHomeScore' => 2, 'predictedAwayScore' => 0];
        $this->assertSame(422, $this->api('POST', '/pickem-picks', 2, [
            'eventId' => 1, 'selectedOutcome' => 'home', 'predictedHomeScore' => 0, 'predictedAwayScore' => 2,
        ])->getStatusCode());
        $response = $this->api('POST', '/pickem-picks', 2, $attributes);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = json_decode((string) $response->getBody(), true)['data'];
        $this->assertSame(2, $data['attributes']['predictedHomeScore']);
        $this->assertSame(0, $data['attributes']['predictedAwayScore']);
        $id = $data['id'];

        foreach ([
            ['predictedHomeScore' => 1],
            ['predictedHomeScore' => -1, 'predictedAwayScore' => 0],
            ['predictedHomeScore' => 1.5, 'predictedAwayScore' => 0],
            ['predictedHomeScore' => 0, 'predictedAwayScore' => 1],
            ['predictedHomeScore' => null, 'predictedAwayScore' => 0],
        ] as $invalid) {
            $response = $this->api('PATCH', '/pickem-picks/'.$id, 2, ['selectedOutcome' => 'home'] + $invalid);
            $this->assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        }

        $this->assertSame(200, $this->api('PATCH', '/pickem-picks/'.$id, 2, [
            'predictedHomeScore' => null, 'predictedAwayScore' => null,
        ])->getStatusCode());
        $this->assertNull(Pick::findOrFail($id)->predicted_home_score);

        $this->assertSame(200, $this->api('PATCH', '/pickem-picks/'.$id, 2, [
            'selectedOutcome' => 'home', 'predictedHomeScore' => 3, 'predictedAwayScore' => 1,
        ])->getStatusCode());
        $this->assertSame(201, $this->api('POST', '/pickem-picks', 2, [
            'eventId' => 1, 'selectedOutcome' => 'home', 'predictedHomeScore' => 4, 'predictedAwayScore' => 1,
        ])->getStatusCode());
        $this->assertSame(4, Pick::findOrFail($id)->predicted_home_score);
        $this->assertSame(200, $this->api('PATCH', '/pickem-picks/'.$id, 2, ['selectedOutcome' => 'away'])->getStatusCode());
        $pick = Pick::findOrFail($id);
        $this->assertNull($pick->predicted_home_score);
        $this->assertNull($pick->predicted_away_score);
    }

    #[Test]
    public function exactScoresAreRecalculatedWhenResultsChange(): void
    {
        $response = $this->api('POST', '/pickem-picks', 2, [
            'eventId' => 1, 'selectedOutcome' => 'home', 'predictedHomeScore' => 2, 'predictedAwayScore' => 0,
        ]);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(200, $this->api('POST', '/pickem-events/1/result', 1, ['homeScore' => 2, 'awayScore' => 0])->getStatusCode());
        $this->assertEquals(2, UserScore::where('user_id', 2)->firstOrFail()->total_points);
        $this->assertSame(200, $this->api('POST', '/pickem/recalculate-all-scores')->getStatusCode());
        $this->assertEquals(2, UserScore::where('user_id', 2)->firstOrFail()->total_points);
        $this->assertSame(200, $this->api('POST', '/pickem-events/1/result', 1, ['homeScore' => 3, 'awayScore' => 0])->getStatusCode());
        $this->assertEquals(1, UserScore::where('user_id', 2)->firstOrFail()->total_points);
        $this->assertSame(200, $this->api('POST', '/pickem-events/1/result', 1, ['homeScore' => 0, 'awayScore' => 1])->getStatusCode());
        $this->assertEquals(0, UserScore::where('user_id', 2)->firstOrFail()->total_points);
    }

    #[Test]
    public function zeroZeroDrawAndCutoffAreHandled(): void
    {
        $response = $this->api('POST', '/pickem-picks', 2, [
            'eventId' => 1, 'selectedOutcome' => 'draw', 'predictedHomeScore' => 0, 'predictedAwayScore' => 0,
        ]);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $id = json_decode((string) $response->getBody(), true)['data']['id'];
        $this->assertSame(200, $this->api('POST', '/pickem-events/1/result', 1, ['homeScore' => 0, 'awayScore' => 0])->getStatusCode());
        $this->assertEquals(2, UserScore::where('user_id', 2)->firstOrFail()->total_points);
        $this->assertSame(422, $this->api('PATCH', '/pickem-picks/'.$id, 2, [
            'predictedHomeScore' => null, 'predictedAwayScore' => null,
        ])->getStatusCode());
    }

    private function newMatch(?int $weekId = null, bool $special = false): Event
    {
        return Event::create([
            'week_id' => $weekId, 'home_team_id' => 1, 'away_team_id' => 2,
            'match_date' => Carbon::now()->addDays(2), 'cutoff_date' => Carbon::now()->addDay(),
            'allow_draw' => true, 'is_special' => $special, 'status' => Event::STATUS_SCHEDULED,
        ]);
    }

    private function score(): float
    {
        return UserScore::where('user_id', 2)->firstOrFail()->total_points;
    }

    private function leaderboardExactCount(string $query = ''): int
    {
        $response = $this->api('GET', '/pickem-user-scores'.$query);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true)['data'][0]['attributes']['exactScoreCount'];
    }

    #[Test]
    public function leaderboardCountsOnlyFinishedCorrectExactScores(): void
    {
        $this->app();
        $draw = $this->newMatch();
        $pending = $this->newMatch();
        foreach ([
            [1, 'home', 2, 0],
            [$draw->id, 'draw', 0, 0],
            [$pending->id, 'away', 0, 1],
        ] as [$eventId, $outcome, $home, $away]) {
            $response = $this->api('POST', '/pickem-picks', 2, [
                'eventId' => $eventId, 'selectedOutcome' => $outcome, 'predictedHomeScore' => $home, 'predictedAwayScore' => $away,
            ]);
            $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        }
        $this->assertSame(200, $this->api('POST', '/pickem-events/1/result', 1, ['homeScore' => 2, 'awayScore' => 0])->getStatusCode());
        $this->assertSame(1, $this->leaderboardExactCount());
        $this->assertSame(1, $this->leaderboardExactCount('?filter[user]=2'));
        $this->assertSame(200, $this->api('POST', "/pickem-events/{$draw->id}/result", 1, ['homeScore' => 0, 'awayScore' => 0])->getStatusCode());
        $this->assertSame(2, $this->leaderboardExactCount());
        $this->assertSame(200, $this->api('POST', "/pickem-events/{$draw->id}/result", 1, ['homeScore' => 1, 'awayScore' => 1])->getStatusCode());
        $this->assertSame(1, $this->leaderboardExactCount());
        $this->assertSame(200, $this->api('PATCH', '/pickem-events/1', 1, ['status' => 'closed'])->getStatusCode());
        $this->assertSame(0, $this->leaderboardExactCount());
    }

    #[Test]
    public function newMatchPointsAndSpecialMultiplierAreRecalculated(): void
    {
        $this->app();
        $event = $this->newMatch();
        $this->assertTrue($event->new_scoring);
        $response = $this->api('POST', '/pickem-picks', 2, [
            'eventId' => $event->id, 'selectedOutcome' => 'draw', 'predictedHomeScore' => 0, 'predictedAwayScore' => 0,
        ]);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(200, $this->api('POST', "/pickem-events/{$event->id}/result", 1, ['homeScore' => 0, 'awayScore' => 0])->getStatusCode());
        $this->assertSame(4.5, $this->score());
        $this->assertSame(200, $this->api('PATCH', "/pickem-events/{$event->id}", 1, ['isSpecial' => true])->getStatusCode());
        $this->assertSame(9.0, $this->score());
        $this->assertSame(200, $this->api('POST', "/pickem-events/{$event->id}/result", 1, ['homeScore' => 1, 'awayScore' => 1])->getStatusCode());
        $this->assertSame(3.0, $this->score());
        $this->assertSame(200, $this->api('PATCH', "/pickem-events/{$event->id}", 1, ['isSpecial' => false])->getStatusCode());
        $this->assertSame(1.5, $this->score());
        $response = $this->api('GET', '/pickem-user-scores');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertEquals(1.5, json_decode((string) $response->getBody(), true)['data'][0]['attributes']['totalPoints']);
        $this->assertSame(200, $this->api('POST', "/pickem-events/{$event->id}/result", 1, ['homeScore' => 1, 'awayScore' => 0])->getStatusCode());
        $this->assertSame(0.0, $this->score());
        $this->assertSame(422, $this->api('PATCH', '/pickem-events/1', 1, ['isSpecial' => true])->getStatusCode());
    }

    #[Test]
    public function weeklyBonusesRespondToResultsAndMembershipChanges(): void
    {
        $this->app();
        Season::create(['id' => 1, 'name' => 'Season', 'slug' => 'season']);
        Week::create(['id' => 1, 'name' => 'Week one', 'season_id' => 1]);
        Week::create(['id' => 2, 'name' => 'Week two', 'season_id' => 1]);
        $first = $this->newMatch(1);
        $second = $this->newMatch(1, true);
        foreach ([$first, $second] as $event) {
            $response = $this->api('POST', '/pickem-picks', 2, [
                'eventId' => $event->id, 'selectedOutcome' => 'home', 'predictedHomeScore' => 2, 'predictedAwayScore' => 0,
            ]);
            $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        }
        $this->assertSame(200, $this->api('POST', "/pickem-events/{$first->id}/result", 1, ['homeScore' => 2, 'awayScore' => 0])->getStatusCode());
        $this->assertSame(4.0, $this->score());
        $this->assertSame(200, $this->api('POST', "/pickem-events/{$second->id}/result", 1, ['homeScore' => 3, 'awayScore' => 0])->getStatusCode());
        $this->assertSame(16.0, $this->score());
        $this->assertSame(200, $this->api('POST', "/pickem-events/{$second->id}/result", 1, ['homeScore' => 2, 'awayScore' => 0])->getStatusCode());
        $this->assertSame(42.0, $this->score());
        $this->assertSame(200, $this->api('POST', "/pickem-events/{$first->id}/result", 1, ['homeScore' => 3, 'awayScore' => 0])->getStatusCode());
        $this->assertSame(19.0, $this->score());
        $this->assertSame(200, $this->api('POST', "/pickem-events/{$first->id}/result", 1, ['homeScore' => 0, 'awayScore' => 1])->getStatusCode());
        $this->assertSame(8.0, $this->score());
        $this->assertSame(200, $this->api('POST', "/pickem-events/{$first->id}/result", 1, ['homeScore' => 2, 'awayScore' => 0])->getStatusCode());
        $this->assertSame(42.0, $this->score());

        $third = $this->newMatch(1);
        $this->assertSame(12.0, $this->score());
        $this->assertSame(200, $this->api('POST', "/pickem-events/{$third->id}/result", 1, ['homeScore' => 1, 'awayScore' => 0])->getStatusCode());
        $this->assertSame(12.0, $this->score());
        $this->assertSame(204, $this->api('DELETE', "/pickem-events/{$third->id}")->getStatusCode());
        $this->assertSame(42.0, $this->score());

        $legacy = Event::findOrFail(1);
        $legacy->week_id = 1;
        $legacy->save();
        $this->assertSame(12.0, $this->score());
        $legacy = Event::findOrFail(1);
        $legacy->week_id = 2;
        $legacy->save();
        $this->assertSame(42.0, $this->score());

        $this->assertSame(200, $this->api('PATCH', "/pickem-events/{$second->id}", 1, ['status' => 'closed'])->getStatusCode());
        $this->assertSame(4.0, $this->score());
        $this->assertNull(Pick::where('event_id', $second->id)->firstOrFail()->is_correct);
    }
}
