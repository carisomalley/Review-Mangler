<?php

/**
 * Classification tick — CLAUDE.md §9.3. Point a second Hostinger cron job at
 * this file, e.g. every 5-10 minutes:
 *   php /home/USER/domains/YOURDOMAIN/review-mangler/cron/classify.php
 * Processes a small batch each run so it stays well inside PHP execution
 * time limits on shared hosting.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Services\ClassificationService;

$result = (new ClassificationService())->processPending(25);

echo sprintf(
    "[%s] classify: processed %d review(s), %d error(s)\n",
    date('Y-m-d H:i:s'),
    $result['processed'],
    count($result['errors'])
);
foreach ($result['errors'] as $err) {
    echo "  - $err\n";
}
