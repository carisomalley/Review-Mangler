<?php

namespace App\Services;

use App\Database;

/**
 * Classification tick (CLAUDE.md §7.3, §9.3). Runs as a small batch per
 * invocation so it fits inside Hostinger's cron/PHP execution time limits.
 */
class ClassificationService
{
    /**
     * @return array{processed:int, errors:array<int,string>}
     */
    public function processPending(int $limit = 25): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT r.id, r.raw_text, t.display_name
             FROM reviews r
             JOIN titles t ON t.id = r.title_id
             WHERE r.classification_status = "pending"
             ORDER BY r.fetched_at ASC
             LIMIT ?'
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $pending = $stmt->fetchAll();

        $classifier = new ClaudeClassifier();
        $processed = 0;
        $errors = [];

        foreach ($pending as $review) {
            try {
                $text = trim($review['raw_text']);
                if ($text === '') {
                    $this->markSkipped((int) $review['id']);
                    continue;
                }

                $result = $classifier->classify($review['display_name'], $text);
                $this->store((int) $review['id'], $result);
                $processed++;
            } catch (\Throwable $e) {
                $errors[] = "review_id={$review['id']}: " . $e->getMessage();
                error_log('Classification error: ' . $e->getMessage());
                $this->markFailed((int) $review['id']);
            }
        }

        return ['processed' => $processed, 'errors' => $errors];
    }

    private function store(int $reviewId, array $result): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO classifications
                    (review_id, sentiment, meanness_score, constructive, personal_attack, content_tags, rubric_version, classified_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $reviewId,
                $result['sentiment'],
                $result['meanness_score'],
                $result['constructive'] ? 1 : 0,
                $result['personal_attack'] ? 1 : 0,
                json_encode($result['content_tags']),
                ClaudeClassifier::RUBRIC_VERSION,
            ]);

            $update = $pdo->prepare('UPDATE reviews SET classification_status = "done" WHERE id = ?');
            $update->execute([$reviewId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function markSkipped(int $reviewId): void
    {
        $stmt = Database::pdo()->prepare('UPDATE reviews SET classification_status = "skipped_empty" WHERE id = ?');
        $stmt->execute([$reviewId]);
    }

    private function markFailed(int $reviewId): void
    {
        $stmt = Database::pdo()->prepare('UPDATE reviews SET classification_status = "failed" WHERE id = ?');
        $stmt->execute([$reviewId]);
    }
}
