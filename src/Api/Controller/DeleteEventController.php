<?php

namespace HuseyinFiliz\Pickem\Api\Controller;

use Flarum\Http\RequestUtil;
use HuseyinFiliz\Pickem\Event;
use HuseyinFiliz\Pickem\Job\RecalculateUserScoreJob;
use HuseyinFiliz\Pickem\Pick;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

class DeleteEventController extends AbstractDeleteController
{
    public function __construct(protected Dispatcher $bus)
    {
    }

    protected function delete(ServerRequestInterface $request): void
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('pickem.manage');

        $id = Arr::get($request->getQueryParams(), 'id');
        $event = Event::findOrFail($id);

        $eventIds = null === $event->week_id ? [$id] : Event::where('week_id', $event->week_id)->pluck('id')->all();
        $affectedUserIds = Pick::whereIn('event_id', $eventIds)
            ->distinct()
            ->pluck('user_id')
            ->all();

        $event->getConnection()->transaction(function () use ($event) {
            $event->picks()->delete();
            $event->delete();
        });

        foreach ($affectedUserIds as $userId) {
            $this->bus->dispatch(
                (new RecalculateUserScoreJob($userId))->afterCommit()
            );
        }
    }
}
