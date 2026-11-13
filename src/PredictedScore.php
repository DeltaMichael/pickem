<?php

namespace HuseyinFiliz\Pickem;

use Flarum\Foundation\ValidationException;
use Illuminate\Contracts\Translation\Translator;

class PredictedScore
{
    public function __construct(protected Translator $translator)
    {
    }

    public function apply(Pick $pick, array $data, string $outcome): void
    {
        $hasHome = array_key_exists('predictedHomeScore', $data);
        $hasAway = array_key_exists('predictedAwayScore', $data);

        if (! $hasHome && ! $hasAway) {
            if ($pick->selected_outcome !== $outcome) {
                $pick->predicted_home_score = null;
                $pick->predicted_away_score = null;
            }

            return;
        }

        $home = $data['predictedHomeScore'] ?? null;
        $away = $data['predictedAwayScore'] ?? null;
        if (! $hasHome || ! $hasAway || ((null === $home) !== (null === $away))) {
            $this->invalid();
        }

        if (null !== $home) {
            if (! is_int($home) || ! is_int($away) || $home < 0 || $away < 0 || $home > 2147483647 || $away > 2147483647) {
                $this->invalid();
            }

            $scoreOutcome = $home > $away ? Event::RESULT_HOME : ($home < $away ? Event::RESULT_AWAY : Event::RESULT_DRAW);
            if ($scoreOutcome !== $outcome) {
                $this->invalid();
            }
        }

        $pick->predicted_home_score = $home;
        $pick->predicted_away_score = $away;
    }

    protected function invalid(): never
    {
        throw new ValidationException(['scores' => $this->translator->trans('huseyinfiliz-pickem.lib.messages.invalid_predicted_scores')]);
    }
}
