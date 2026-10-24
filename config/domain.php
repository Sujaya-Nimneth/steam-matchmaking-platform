<?php
declare(strict_types=1);

/**
 * Domain vocabulary shared by the API endpoints and the rendered SPA, so the
 * playstyle list can never drift between validation, filtering and markup.
 */

const PLAYSTYLES = ['Chill', 'Competitive', 'Tryhard', 'Toxic-Free'];

/** Human-friendly labels used by the setup and filter selects. */
const PLAYSTYLE_LABELS = [
    'Chill' => 'Chill / Casual',
    'Competitive' => 'Competitive / Ranked',
    'Tryhard' => 'Tryhard',
    'Toxic-Free' => 'Toxic-Free',
];
