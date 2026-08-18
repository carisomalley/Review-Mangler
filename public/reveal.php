<?php

require __DIR__ . '/../app/bootstrap.php';

use App\Auth;
use App\Services\ReviewService;

$userId = Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$reviewId = (int) ($_POST['review_id'] ?? 0);
$returnTo = (int) ($_POST['return_to'] ?? 0);

// Ownership check happens inside getRevealableText — a review only reveals
// if it belongs to a title THIS user tracks (CLAUDE.md §5, §8).
$review = (new ReviewService())->getRevealableText($userId, $reviewId);
if ($review) {
    $_SESSION['revealed'] = $_SESSION['revealed'] ?? [];
    if (!in_array($reviewId, $_SESSION['revealed'], true)) {
        $_SESSION['revealed'][] = $reviewId;
    }
}

header('Location: /title.php?id=' . $returnTo . '#review-' . $reviewId);
exit;
