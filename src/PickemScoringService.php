<?php

namespace HuseyinFiliz\Pickem;

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
            $this->updatePicksForEvent($event);
            $this->updateUserScoresForEvent($event);
        });
    }

    /**
     * Event için tüm pick'lerin doğruluğunu güncelle
     * Toplu güncelleme (mass update) kullanarak performansı artırıyoruz.
     */
    protected function updatePicksForEvent(Event $event): void
    {
        // Sonuç null ise (örn: iptal edildi veya geri alındı), is_correct null olmalı
        if (null === $event->result) {
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
        $grammar = $this->db->getQueryGrammar();
        $correct = $grammar->wrap('pickem_picks.is_correct');
        $predictedHome = $grammar->wrap('pickem_picks.predicted_home_score');
        $predictedAway = $grammar->wrap('pickem_picks.predicted_away_score');
        $homeScore = $grammar->wrap('pickem_events.home_score');
        $awayScore = $grammar->wrap('pickem_events.away_score');

        $stats = Pick::query()
            ->join('pickem_events', 'pickem_events.id', '=', 'pickem_picks.event_id')
            ->where('pickem_picks.user_id', $userId)
            ->selectRaw("count(*) as total, sum(case when $correct = ? then 1 else 0 end) as correct", [true])
            ->selectRaw("sum(case when $correct = ? and $predictedHome = $homeScore and $predictedAway = $awayScore then 1 else 0 end) as exact", [true])
            ->whereNotNull('pickem_picks.is_correct')
            ->first();

        $totalPicks = $stats ? (int) $stats->total : 0;
        $correctPicks = $stats ? (int) $stats->correct : 0;

        $totalPoints = $correctPicks + ($stats ? (int) $stats->exact : 0);

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
