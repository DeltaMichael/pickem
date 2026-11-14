<?php

namespace HuseyinFiliz\Pickem\Listener;

use HuseyinFiliz\Pickem\Event;
use HuseyinFiliz\Pickem\Job\ProcessEventResultsJob;
use HuseyinFiliz\Pickem\Job\RecalculateUserScoreJob;
use HuseyinFiliz\Pickem\Job\RefreshEventScoresJob;
use HuseyinFiliz\Pickem\Pick;
use Illuminate\Contracts\Bus\Dispatcher;

class UpdateUserScoresListener
{
    public function __construct(protected Dispatcher $bus)
    {
    }

    public function handle(Event $event): void
    {
        $resultChanged = $event->wasChanged(['result', 'home_score', 'away_score', 'status']);
        if ($event->isFinished() && null !== $event->result && $resultChanged) {
            $this->bus->dispatch((new ProcessEventResultsJob($event->id))->afterCommit());
        } elseif ($resultChanged) {
            $this->bus->dispatch((new RefreshEventScoresJob($event->id))->afterCommit());
        }

        if ($event->wasRecentlyCreated || $event->wasChanged(['result', 'home_score', 'away_score', 'status', 'is_special', 'week_id'])) {
            $weekIds = array_filter([$event->week_id, $event->getRawOriginal('week_id')], fn ($id) => null !== $id);
            $eventIds = Event::whereIn('week_id', $weekIds)->pluck('id')->all();
            $eventIds[] = $event->id;
            $userIds = Pick::whereIn('event_id', $eventIds)->distinct()->pluck('user_id');
            foreach ($userIds as $userId) {
                $this->bus->dispatch((new RecalculateUserScoreJob($userId))->afterCommit());
            }
        }
    }
}
