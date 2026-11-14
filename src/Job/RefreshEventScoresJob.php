<?php

namespace HuseyinFiliz\Pickem\Job;

use Flarum\Queue\AbstractJob;
use HuseyinFiliz\Pickem\Event;
use HuseyinFiliz\Pickem\PickemScoringService;

class RefreshEventScoresJob extends AbstractJob
{
    public function __construct(protected int $eventId)
    {
    }

    public function handle(PickemScoringService $scoring): void
    {
        $event = Event::find($this->eventId);
        if ($event) {
            $scoring->updateScoresForEvent($event);
        }
    }
}
