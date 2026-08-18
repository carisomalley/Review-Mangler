<?php

/**
 * Digest tick — CLAUDE.md §7.6, §9.3. Point a third Hostinger cron job at
 * this file, once a day is plenty (the service itself decides per-title
 * whether anything is actually due to send):
 *   php /home/USER/domains/YOURDOMAIN/review-mangler/cron/digest.php
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Services\NotificationService;

$result = (new NotificationService())->sendDue();

echo sprintf(
    "[%s] digest: sent %d, skipped %d, %d error(s)\n",
    date('Y-m-d H:i:s'),
    $result['sent'],
    $result['skipped'],
    count($result['errors'])
);
foreach ($result['errors'] as $err) {
    echo "  - $err\n";
}
