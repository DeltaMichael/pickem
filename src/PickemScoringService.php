<?php

namespace HuseyinFiliz\Pickem;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface; // DÜZELTME: Facade yerine Interface

/**
 * Skorlama mantığını merkezileştiren servis sınıfı.
 */
class PickemScoringService
{
    /**
     * Veritabanı bağlantısını enjekte ediyoruz.
     */
    public function __construct(protected ConnectionInterface $db)
    {
    }

    /**
     * Bir maçın sonucu girildiğinde tüm tahminleri ve skorları günceller.
     */
    public function updateScoresForEvent(Event $event): void
    {
        // Transaction kullanarak veri bütünlüğünü sağlayalım
        // DÜZELTME: DB::transaction yerine $this->db->transaction
        $this->db->transaction(function () use ($event) {
            $current = Event::whereKey($event->id)->lockForUpdate()->first();
            if ($current) {
                $this->updatePicksForEvent($current);
            }
        });
        $this->updateUserScoresForEvent($event);
    }

    /**
     * Event için tüm pick'lerin doğruluğunu güncelle
     * Toplu güncelleme (mass update) kullanarak performansı artırıyoruz.
     */
    protected function updatePicksForEvent(Event $event): void
    {
        // Sonuç null ise (örn: iptal edildi veya geri alındı), is_correct null olmalı
        if (! $event->isFinished() || null === $event->result) {
            Pick::where('event_id', $event->id)->update(['is_correct' => null]);

            return;
        }

        // Doğru tahminleri işaretle
        Pick::where('event_id', $event->id)
            ->where('selected_outcome', $event->result)
            ->update(['is_correct' => true]);

        // Yanlış tahminleri işaretle
        Pick::where('event_id', $event->id)
            ->where('selected_outcome', '!=', $event->result)
            ->update(['is_correct' => false]);
    }

    /**
     * Bu event için pick yapan kullanıcıların score'larını güncelle.
     */
    protected function updateUserScoresForEvent(Event $event): void
    {
        // Sadece bu maç için tahminde bulunan kullanıcıların ID'lerini al
        $userIds = Pick::where('event_id', $event->id)
            ->distinct()
            ->pluck('user_id');

        // Her kullanıcı için skoru yeniden hesapla
        foreach ($userIds as $userId) {
            $this->recalculateUserScore($userId);
        }
    }

    /**
     * Kullanıcının score'unu yeniden hesapla (Global Leaderboard - Sezon ID her zaman NULL).
     */
    public function recalculateUserScore(int $userId): void
    {
        $this->db->transaction(function () use ($userId) {
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $this->calculateUserScore($userId);
        });
    }

    protected function calculateUserScore(int $userId): void
    {
        $picks = Pick::where('user_id', $userId)->with('event')->get();
        $totalPicks = 0;
        $correctPicks = 0;
        $halfPoints = 0;
        foreach ($picks as $pick) {
            $event = $pick->event;
            if (! $event || ! $event->isFinished() || null === $event->result) {
                continue;
            }
            $totalPicks++;
            if ($pick->selected_outcome !== $event->result) {
                continue;
            }
            $correctPicks++;
            $exact = null !== $pick->predicted_home_score && null !== $pick->predicted_away_score
                && $pick->predicted_home_score === $event->home_score && $pick->predicted_away_score === $event->away_score;
            if (! $event->new_scoring) {
                $halfPoints += 2 + ($exact ? 2 : 0);
            } else {
                $matchPoints = (Event::RESULT_DRAW === $event->result ? 3 : 2) + ($exact ? 6 : 0);
                $halfPoints += $matchPoints * ($event->is_special ? 2 : 1);
            }
        }

        $weekIds = $picks->map(fn (Pick $pick) => $pick->event?->week_id)->filter(fn ($id) => null !== $id)->unique()->values();
        $weeks = Event::whereIn('week_id', $weekIds)->get()->groupBy('week_id');
        $picksByEvent = $picks->keyBy('event_id');
        foreach ($weeks as $events) {
            $allCorrect = true;
            $allExact = true;
            foreach ($events as $event) {
                $pick = $picksByEvent->get($event->id);
                if (! $event->new_scoring || ! $event->isFinished() || null === $event->result
                    || ! $pick || $pick->selected_outcome !== $event->result) {
                    $allCorrect = false;
                    break;
                }
                if (null === $pick->predicted_home_score || null === $pick->predicted_away_score
                    || $pick->predicted_home_score !== $event->home_score || $pick->predicted_away_score !== $event->away_score) {
                    $allExact = false;
                }
            }
            if ($allCorrect) {
                $halfPoints += 20 + ($allExact ? 40 : 0);
            }
        }
        $totalPoints = $halfPoints / 2;

        // Accuracy hesaplama
        $accuracy = $totalPicks > 0 ? round(($correctPicks / $totalPicks) * 100, 2) : 0;

        // 2. Skor kaydını güncelle veya oluştur (Sezon ID her zaman NULL)
        UserScore::updateOrCreate(
            [
                'user_id' => $userId,
                'season_id' => null,
            ],
            [
                'total_points' => $totalPoints,
                'total_picks' => $totalPicks,
                'correct_picks' => $correctPicks,
                'accuracy' => $accuracy
            ]
        );
    }
}
