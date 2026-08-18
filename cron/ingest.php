<?php

/**
 * Ingestion tick — CLAUDE.md §9.3. Point a Hostinger cron job at this file,
 * e.g. every 30 minutes:
 *   php /home/USER/domains/YOURDOMAIN/review-mangler/cron/ingest.php
 * (Titles are only actually fetched when they're due per their own
 * refresh_cadence_hours, so running this often is cheap and safe.)
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Services\IngestionService;

$result = (new IngestionService())->runDue();

echo sprintf(
    "[%s] ingest: checked %d title(s), added %d review(s), %d error(s)\n",
    date('Y-m-d H:i:s'),
    $result['titles_checked'],
    $result['reviews_added'],
    count($result['errors'])
);
foreach ($result['errors'] as $err) {
    echo "  - $err\n";
}
