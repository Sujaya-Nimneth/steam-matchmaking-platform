<?php
// services/MatchEngine.php

class MatchEngine {
    
    // Calculates compatibility score (0-100) between User A and User B
    public static function calculateScore($userA, $userB, $gamesA, $gamesB) {
        $sharedGames = 0;
        $score = 0;

        // Build associative array for faster lookup
        $mapB = [];
        foreach ($gamesB as $gb) {
            $mapB[$gb['app_id']] = $gb;
        }

        $gameDetails = [];

        foreach ($gamesA as $ga) {
            $appId = $ga['app_id'];
            if (isset($mapB[$appId])) {
                $sharedGames++;
                $gb = $mapB[$appId];

                $hoursA = max(1, $ga['playtime_forever'] / 60); // Convert mins to hours, min 1
                $hoursB = max(1, $gb['playtime_forever'] / 60);

                // Math Formula: 1 - (|log(hoursA) - log(hoursB)| / max(log(hoursA), log(hoursB)))
                $logA = log($hoursA + 1); // +1 to avoid log(1) = 0 division issues if both are 1 hour
                $logB = log($hoursB + 1);
                $maxLog = max($logA, $logB);
                
                $diff = abs($logA - $logB);
                $gameScoreWeight = 1 - ($diff / $maxLog);

                // Apply Recent Activity Multiplier (1.5x) if both played in last 2 weeks
                if ($ga['playtime_2weeks'] > 0 && $gb['playtime_2weeks'] > 0) {
                    $gameScoreWeight *= 1.5;
                }

                $score += $gameScoreWeight;

                // Save game details for UI
                $gameDetails[] = [
                    'app_id' => $appId,
                    'hours_a' => round($hoursA, 1),
                    'hours_b' => round($hoursB, 1),
                    'weight' => $gameScoreWeight
                ];
            }
        }

        if ($sharedGames === 0) {
            return ['score' => 0, 'shared_games' => []];
        }

        // Normalize score relative to number of shared games to get a percentage-like base
        // But we want people with MORE shared games to score higher. 
        // We will sum the weights, and then cap it. Let's say 10 perfect game matches = 100 base score.
        // So base_score = (sum_weights / 10) * 100 = sum_weights * 10.
        $finalScore = $score * 10;

        // Playstyle match bonus
        if (!empty($userA['playstyle']) && $userA['playstyle'] === $userB['playstyle']) {
            $finalScore += 10;
        }

        $finalScore = min(100, max(0, round($finalScore)));

        // Sort game details by weight descending
        usort($gameDetails, function($a, $b) {
            return $b['weight'] <=> $a['weight'];
        });

        return [
            'score' => (int)$finalScore,
            'shared_games' => array_slice($gameDetails, 0, 3) // Top 3 shared games
        ];
    }
}
