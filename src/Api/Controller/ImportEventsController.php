<?php

namespace HuseyinFiliz\Pickem\Api\Controller;

use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use HuseyinFiliz\Pickem\Event;
use HuseyinFiliz\Pickem\Team;
use HuseyinFiliz\Pickem\Validator\TeamValidator;
use HuseyinFiliz\Pickem\Week;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException as TeamValidationException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ImportEventsController implements RequestHandlerInterface
{
    public function __construct(protected ConnectionInterface $db, protected Translator $translator, protected TeamValidator $teamValidator)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('pickem.manage');
        $data = Arr::get($request->getParsedBody(), 'data.attributes', []);
        $seasonId = Arr::get($data, 'seasonId');
        $weekId = Arr::get($data, 'weekId');
        $csv = Arr::get($data, 'csv');
        $timezone = Arr::get($data, 'timezone');

        if (! is_string($csv) || strlen($csv) > 65536 || str_contains($csv, "\0") || ! is_string($timezone)
            || ! in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            $this->fail('invalid_input');
        }

        if (! is_numeric($seasonId) || ! is_numeric($weekId)
            || ! Week::where('id', $weekId)->where('season_id', $seasonId)->exists()) {
            $this->fail('invalid_week');
        }

        $timezone = new \DateTimeZone($timezone);
        $teams = Team::all()->keyBy('slug');
        $rows = preg_split('/\r\n|\n|\r/', $csv);
        $events = [];
        $newSlugs = [];
        $errors = [];
        $headerSeen = false;
        $rowCount = 0;

        foreach ($rows as $index => $line) {
            $line = trim(0 === $index ? preg_replace('/^\xEF\xBB\xBF/', '', $line) : $line);
            if ('' === $line) {
                continue;
            }

            if (++$rowCount > 201) {
                $this->fail('too_many');
            }

            if (! preg_match('/^\s*(?:"[^"]*"|[^",]*)\s*,\s*(?:"[^"]*"|[^",]*)\s*,\s*(?:"[^"]*"|[^",]*)\s*$/u', $line)) {
                $errors[] = $this->rowError($index + 1, 'invalid_columns');
                continue;
            }

            $fields = array_map('trim', str_getcsv($line, ',', '"', ''));
            if (1 === $rowCount && $fields === ['home_slug', 'away_slug', 'start_time']) {
                $headerSeen = true;
                continue;
            }

            [$home, $away, $start] = $fields;
            if ($home === $away) {
                $errors[] = $this->rowError($index + 1, 'same_team');
                continue;
            }

            $validTeams = true;
            foreach ([$home, $away] as $slug) {
                if ($teams->has($slug) || isset($newSlugs[$slug])) {
                    continue;
                }
                try {
                    $this->teamValidator->assertValid(['name' => $slug, 'slug' => $slug]);
                    $newSlugs[$slug] = true;
                } catch (TeamValidationException $exception) {
                    $validTeams = false;
                }
            }
            if (! $validTeams) {
                $errors[] = $this->rowError($index + 1, 'invalid_slug');
                continue;
            }

            $normalized = str_replace('T', ' ', $start);
            if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/D', $normalized)) {
                $errors[] = $this->rowError($index + 1, 'invalid_date');
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $normalized, $timezone);
            if (! $date || $date->format('Y-m-d H:i') !== $normalized) {
                $errors[] = $this->rowError($index + 1, 'invalid_date');
                continue;
            }

            // Match JavaScript's choice of the earlier instant when clocks repeat an hour.
            $timestamp = $date->getTimestamp();
            $wallTime = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $normalized, new \DateTimeZone('UTC'))->getTimestamp();
            foreach ($timezone->getTransitions($timestamp - 172800, $timestamp + 172800) as $transition) {
                $candidate = $wallTime - $transition['offset'];
                if ($candidate < $timestamp && (new \DateTimeImmutable('@'.$candidate))->setTimezone($timezone)->format('Y-m-d H:i') === $normalized) {
                    $timestamp = $candidate;
                }
            }
            $utc = new \DateTimeImmutable('@'.$timestamp);
            $cutoff = $utc->modify('-1 minute');
            if ((int) $cutoff->format('Y') < 1000 || (int) $utc->format('Y') > 9999) {
                $errors[] = $this->rowError($index + 1, 'invalid_date');
                continue;
            }
            $events[] = [
                'week_id' => (int) $weekId,
                'home_slug' => $home,
                'away_slug' => $away,
                'match_date' => $utc->format('Y-m-d H:i:s'),
                'cutoff_date' => $cutoff->format('Y-m-d H:i:s'),
                'allow_draw' => true,
                'status' => Event::STATUS_SCHEDULED,
            ];
        }

        if ($rowCount - (int) $headerSeen > 200) {
            $this->fail('too_many');
        }
        if ($errors) {
            throw new ValidationException(['csv' => implode("\n", $errors)]);
        }
        if (! $events) {
            $this->fail('empty');
        }

        $createdTeams = $this->db->transaction(function () use ($events, $newSlugs, $teams) {
            $created = [];
            foreach (array_keys($newSlugs) as $slug) {
                $team = Team::firstOrCreate(['slug' => (string) $slug], ['name' => (string) $slug]);
                $teams->put($slug, $team);
                $created[] = [
                    'type' => 'pickem-teams',
                    'id' => (string) $team->id,
                    'attributes' => ['name' => $team->name, 'slug' => $team->slug, 'logoUrl' => $team->logo_url],
                ];
            }
            foreach ($events as $attributes) {
                $attributes['home_team_id'] = $teams[$attributes['home_slug']]->id;
                $attributes['away_team_id'] = $teams[$attributes['away_slug']]->id;
                unset($attributes['home_slug'], $attributes['away_slug']);
                Event::create($attributes);
            }

            return $created;
        });

        return new JsonResponse(['count' => count($events), 'teams' => $createdTeams], 201);
    }

    protected function rowError(int $row, string $key): string
    {
        return $this->translator->trans('huseyinfiliz-pickem.admin.import.row_error', [
            '{row}' => $row,
            '{error}' => $this->translator->trans('huseyinfiliz-pickem.admin.import.'.$key),
        ]);
    }

    protected function fail(string $key): never
    {
        throw new ValidationException(['csv' => $this->translator->trans('huseyinfiliz-pickem.admin.import.'.$key)]);
    }
}
