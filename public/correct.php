<?php

require __DIR__ . '/../app/bootstrap.php';

use App\Auth;
use App\Services\ReviewService;

$userId = Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$classificationId = (int) ($_POST['classification_id'] ?? 0);
$returnTo = (int) ($_POST['return_to'] ?? 0);
$note = trim($_POST['note'] ?? '');

(new ReviewService())->fileCorrection($userId, $classificationId, $note);

header('Location: /title.php?id=' . $returnTo);
exit;
