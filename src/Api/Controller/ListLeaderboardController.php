<?php

namespace HuseyinFiliz\Pickem\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use HuseyinFiliz\Pickem\Api\Resource\UserScoreResource;
use HuseyinFiliz\Pickem\Event;
use HuseyinFiliz\Pickem\Pick;
use HuseyinFiliz\Pickem\UserScore;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

class ListLeaderboardController extends AbstractListController
{
    public $resource = UserScoreResource::class;

    public $include = ['user'];

    public $limit = 20;

    public function __construct(protected UrlGenerator $url)
    {
    }

    protected function data(ServerRequestInterface $request, Document $document)
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('pickem.view');

        $query = UserScore::query();
        $query->whereNull('season_id');

        $filters = $this->extractFilter($request);
        $filterUser = Arr::get($filters, 'user');

        if ($filterUser) {
            $query->where('user_id', $filterUser);
        }

        $query->orderBy('total_points', 'desc')
              ->orderBy('correct_picks', 'desc');

        $query->with(['user']);

        $limit = $this->extractLimit($request);
        $offset = $this->extractOffset($request);

        $total = $query->count();

        $results = $query->limit($limit)
                         ->offset($offset)
                         ->get();

        $exactCounts = Pick::query()
            ->join('pickem_events', 'pickem_picks.event_id', '=', 'pickem_events.id')
            ->whereIn('pickem_picks.user_id', $results->pluck('user_id'))
            ->where('pickem_events.status', Event::STATUS_FINISHED)
            ->whereColumn('pickem_picks.selected_outcome', 'pickem_events.result')
            ->whereColumn('pickem_picks.predicted_home_score', 'pickem_events.home_score')
            ->whereColumn('pickem_picks.predicted_away_score', 'pickem_events.away_score')
            ->select('pickem_picks.user_id')
            ->selectRaw('COUNT(*) as exact_count')
            ->groupBy('pickem_picks.user_id')
            ->pluck('exact_count', 'user_id');

        $results->each(function (UserScore $score) use ($exactCounts) {
            $score->exactScoreCount = (int) $exactCounts->get($score->user_id, 0);
        });

        // N+1 Çözümü: Eğer belirli bir kullanıcı filtrelenmiyorsa (Genel Liste),
        // sıralamayı veritabanı yerine burada hesapla.
        if (! $filterUser) {
            $startRank = $offset + 1;
            $results->each(function ($score, $index) use ($startRank) {
                $score->calculatedRank = $startRank + $index;
            });
        }

        $document->addPaginationLinks(
            $this->url->to('api')->route('pickem.leaderboard.index'),
            $request->getQueryParams(),
            $offset,
            $limit,
            $total
        );

        $document->setMeta(['total' => $total]);

        return $results;
    }
}
