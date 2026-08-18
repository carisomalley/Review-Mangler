<?php

require __DIR__ . '/../app/bootstrap.php';

use App\Auth;
use App\Database;
use App\Services\TitleService;

$userId = Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$trackedTitleId = (int) ($_POST['tracked_title_id'] ?? 0);
$cadence = $_POST['notification_cadence'] ?? 'off';
if (!in_array($cadence, ['off', 'weekly', 'on_new_activity'], true)) {
    $cadence = 'off';
}

// Ownership check before writing anything (CLAUDE.md §5, §8).
$tracked = (new TitleService())->getOwnedTrackedTitle($userId, $trackedTitleId);
if ($tracked) {
    $stmt = Database::pdo()->prepare('UPDATE tracked_titles SET notification_cadence = ? WHERE id = ?');
    $stmt->execute([$cadence, $trackedTitleId]);
}

header('Location: /title.php?id=' . $trackedTitleId);
exit;
