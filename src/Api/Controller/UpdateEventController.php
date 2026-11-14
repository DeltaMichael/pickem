<?php

namespace HuseyinFiliz\Pickem\Api\Controller;

use Carbon\Carbon;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use HuseyinFiliz\Pickem\Api\Resource\EventResource;
use HuseyinFiliz\Pickem\Event;
use HuseyinFiliz\Pickem\Validator\EventValidator;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Psr\Http\Message\ServerRequestInterface;

class UpdateEventController extends AbstractShowController
{
    public $resource = EventResource::class;
    public $include = ['homeTeam', 'awayTeam', 'week'];

    public function __construct(protected EventValidator $validator, protected Translator $translator)
    {
    }

    protected function data(ServerRequestInterface $request, Document $document)
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('pickem.manage');

        $id = Arr::get($request->getQueryParams(), 'id');
        $event = Event::with(['homeTeam', 'awayTeam', 'week'])->findOrFail($id);

        $data = Arr::get($request->getParsedBody(), 'data.attributes', []);
        $attributes = [];
        foreach ($data as $key => $value) {
            $attributes[Str::snake($key)] = $value;
        }
        if (! $event->new_scoring && ! empty($attributes['is_special'])) {
            throw new ValidationException(['isSpecial' => $this->translator->trans('huseyinfiliz-pickem.lib.messages.legacy_special')]);
        }

        $this->validator->model = $event;
        $current = [];
        foreach ($event->getAttributes() as $key => $value) {
            $current[Str::camel($key)] = $value;
        }
        $this->validator->assertValid(array_merge($current, $data));

        if ($matchDate = Arr::get($attributes, 'match_date')) {
            $attributes['match_date'] = Carbon::parse($matchDate);
        }
        if ($cutoffDate = Arr::get($attributes, 'cutoff_date')) {
            $attributes['cutoff_date'] = Carbon::parse($cutoffDate);
        }

        $event->fill($attributes);
        $event->save();

        $event->load(['homeTeam', 'awayTeam', 'week']);

        return $event;
    }
}
