<?php
declare(strict_types=1);

class MatchEngine
{
    /** Weight of each signal in the compatibility score. */
    private const WEIGHT_COVERAGE = 0.35;
    private const WEIGHT_DEPTH = 0.20;
    private const WEIGHT_PLAYTIME = 0.30;
    private const WEIGHT_RECENCY = 0.15;

    private const PLAYSTYLE_BONUS = 5;
    private const MAX_SHARED_GAME_DETAILS = 3;

    /**
     * Calculates a compatibility score (0-100) between two players.
     *
     * $gamesA / $gamesB only need to contain the games they share. Library
     * sizes are used to judge how much of each library overlaps; when they are
     * not supplied the supplied game lists are used as a fallback.
     */
    public static function calculateScore(
        array $userA,
        array $userB,
        array $gamesA,
        array $gamesB,
        int $libraryA = 0,
        int $libraryB = 0
    ): array {
        $mapB = [];
        foreach ($gamesB as $gameB) {
            $mapB[(int) $gameB['app_id']] = $gameB;
        }

        $libraryA = max($libraryA, count($gamesA));
        $libraryB = max($libraryB, count($gamesB));

        $sharedGames = 0;
        $playtimeScore = 0.0;
        $recentlyPlayed = 0;
        $gameDetails = [];

        foreach ($gamesA as $gameA) {
            $appId = (int) $gameA['app_id'];

            if (!isset($mapB[$appId])) {
                continue;
            }

            $gameB = $mapB[$appId];
            $sharedGames++;

            $hoursA = max(1.0, ((int) $gameA['playtime_forever']) / 60);
            $hoursB = max(1.0, ((int) $gameB['playtime_forever']) / 60);

            // 1 - |log(a) - log(b)| / max(log(a), log(b)) keeps the score
            // bounded for wildly different playtimes while still rewarding
            // players who are at a similar stage in a game.
            $logA = log($hoursA + 1);
            $logB = log($hoursB + 1);
            $similarity = 1 - (abs($logA - $logB) / max($logA, $logB));

            $bothRecentlyPlayed = ((int) $gameA['playtime_2weeks']) > 0
                && ((int) $gameB['playtime_2weeks']) > 0;

            if ($bothRecentlyPlayed) {
                $recentlyPlayed++;
            }

            $playtimeScore += $similarity;

            $gameDetails[] = [
                'app_id' => $appId,
                'name' => $gameA['name'] ?? ($gameB['name'] ?? ''),
                'hours_a' => round($hoursA, 1),
                'hours_b' => round($hoursB, 1),
                'recent' => $bothRecentlyPlayed,
                'weight' => round($similarity * ($bothRecentlyPlayed ? 1.5 : 1.0), 4),
            ];
        }

        if ($sharedGames === 0) {
            return ['score' => 0, 'shared_games' => [], 'shared_count' => 0];
        }

        // How much of the smaller library overlaps: rewards genuinely similar
        // tastes instead of just owning a large library.
        $coverage = $sharedGames / max(1, min($libraryA, $libraryB));

        // Diminishing returns: 10 shared games already counts as a deep overlap.
        $depth = min(1.0, $sharedGames / 10);

        $playtimeScore /= $sharedGames;
        $recency = $recentlyPlayed / $sharedGames;

        $finalScore = 100 * (
            self::WEIGHT_COVERAGE * $coverage
            + self::WEIGHT_DEPTH * $depth
            + self::WEIGHT_PLAYTIME * $playtimeScore
            + self::WEIGHT_RECENCY * $recency
        );

        if (!empty($userA['playstyle']) && ($userA['playstyle'] ?? null) === ($userB['playstyle'] ?? null)) {
            $finalScore += self::PLAYSTYLE_BONUS;
        }

        $finalScore = min(100.0, max(0.0, $finalScore));

        usort($gameDetails, static function (array $a, array $b) {
            return [$b['weight'], $b['hours_b']] <=> [$a['weight'], $a['hours_b']];
        });

        return [
            'score' => (int) round($finalScore),
            'shared_games' => array_slice($gameDetails, 0, self::MAX_SHARED_GAME_DETAILS),
            'shared_count' => $sharedGames,
        ];
    }
}
