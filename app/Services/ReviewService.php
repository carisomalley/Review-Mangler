<?php

namespace App\Services;

use App\Database;

/**
 * Ownership-checked lookups for the reveal (§7.5) and correction (§4, §7.3)
 * flows. Every method here verifies the review belongs to a title the
 * requesting user actually tracks — never trust a bare review_id from a form.
 */
class ReviewService
{
    /**
     * Returns the raw review text only if the given user tracks the title
     * this review belongs to. Returns null otherwise (treat as "not found").
     */
    public function getRevealableText(int $userId, int $reviewId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT r.id, r.raw_text, r.external_url, r.headline
             FROM reviews r
             JOIN tracked_titles tt ON tt.title_id = r.title_id
             WHERE r.id = ? AND tt.user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$reviewId, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function fileCorrection(int $userId, int $classificationId, string $note): bool
    {
        $pdo = Database::pdo();
        $check = $pdo->prepare(
            'SELECT c.id
             FROM classifications c
             JOIN reviews r ON r.id = c.review_id
             JOIN tracked_titles tt ON tt.title_id = r.title_id
             WHERE c.id = ? AND tt.user_id = ?
             LIMIT 1'
        );
        $check->execute([$classificationId, $userId]);
        if (!$check->fetch()) {
            return false;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO corrections (classification_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())'
        );
        $stmt->execute([$classificationId, $userId, $note]);
        return true;
    }
}
