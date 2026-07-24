<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Maximum time entry duration (hours)
    |--------------------------------------------------------------------------
    |
    | Absolute upper bound for a single time entry's duration (end - start),
    | enforced on create/update. This is an abuse ceiling that rejects
    | fat-fingered values like "9999h"; the UI applies a lower confirmation
    | threshold before this cap is ever reached. Raise it if you legitimately
    | import entries longer than this.
    |
    */
    'max_duration_hours' => (int) env('TIME_ENTRY_MAX_DURATION_HOURS', 168),
];
