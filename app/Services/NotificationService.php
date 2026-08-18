<?php

namespace App\Services;

use App\Database;

/**
 * Digest emails (CLAUDE.md §7.6). Two cadences: "weekly" (always sends,
 * even if nothing changed — a heartbeat) and "on_new_activity" (only sends
 * when there's something new, batched rather than one email per review).
 * Respects users.vacation_mode — a paused account gets nothing, full stop
 * (CLAUDE.md §1, §7.6: this is a wellbeing feature, not a nice-to-have).
 */
class NotificationService
{
    /**
     * @return array{sent:int, skipped:int, errors:array<int,string>}
     */
    public function sendDue(): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->query(
            "SELECT tt.id AS tracked_title_id, tt.title_id, tt.notification_cadence, tt.last_digest_sent_at,
                    t.display_name, u.id AS user_id, u.email, u.vacation_mode
             FROM tracked_titles tt
             JOIN titles t ON t.id = tt.title_id
             JOIN users u ON u.id = tt.user_id
             WHERE tt.notification_cadence != 'off'"
        );
        $candidates = $stmt->fetchAll();

        $sent = 0;
        $skipped = 0;
        $errors = [];
        $dashboard = new DashboardService();
        $mailer = new SmtpMailer();

        foreach ($candidates as $row) {
            try {
                if ((int) $row['vacation_mode'] === 1) {
                    $skipped++;
                    continue;
                }

                if (!$this->isDue($row)) {
                    $skipped++;
                    continue;
                }

                $activity = $dashboard->newActivitySince(
                    (int) $row['title_id'],
                    (int) $row['tracked_title_id'],
                    $row['last_digest_sent_at']
                );
                $newCount = (int) ($activity['total'] ?? 0);

                // "on_new_activity" only actually sends if something changed;
                // "weekly" sends a heartbeat either way so the creator knows
                // the tool is still watching, per §7.6.
                if ($row['notification_cadence'] === 'on_new_activity' && $newCount === 0) {
                    $this->markSent((int) $row['tracked_title_id']); // still advance the "since" pointer
                    $skipped++;
                    continue;
                }

                $subject = $newCount > 0
                    ? "Review Mangler: {$newCount} new item(s) for \"{$row['display_name']}\""
                    : "Review Mangler: no new activity for \"{$row['display_name']}\" this week";

                $body = $this->composeBody($row['display_name'], $activity);

                if ($mailer->send($row['email'], $subject, $body)) {
                    $this->logNotification((int) $row['user_id'], (int) $row['tracked_title_id'], $subject);
                    $this->markSent((int) $row['tracked_title_id']);
                    $sent++;
                } else {
                    $errors[] = "tracked_title_id={$row['tracked_title_id']}: mailer returned false";
                }
            } catch (\Throwable $e) {
                $errors[] = "tracked_title_id={$row['tracked_title_id']}: " . $e->getMessage();
                error_log('Digest error: ' . $e->getMessage());
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'errors' => $errors];
    }

    private function isDue(array $row): bool
    {
        if ($row['last_digest_sent_at'] === null) {
            return true;
        }
        if ($row['notification_cadence'] === 'on_new_activity') {
            // Batched, not one email per review (§7.6) — check at most once a day.
            return strtotime($row['last_digest_sent_at']) <= strtotime('-1 day');
        }
        // weekly
        return strtotime($row['last_digest_sent_at']) <= strtotime('-7 days');
    }

    private function composeBody(string $displayName, array $activity): string
    {
        $total = (int) ($activity['total'] ?? 0);
        if ($total === 0) {
            return "No new reviews or write-ups found for \"{$displayName}\" since the last check.\n\n"
                . "Log in to see full details: this is just a heartbeat so you know the tool is still watching.";
        }

        $lines = [
            "New activity for \"{$displayName}\":",
            "",
            "  {$total} new item(s) scored",
            "  " . (int) ($activity['positive'] ?? 0) . " positive, "
                . (int) ($activity['mixed'] ?? 0) . " mixed, "
                . (int) ($activity['negative'] ?? 0) . " negative",
        ];

        $personalAttacks = (int) ($activity['personal_attack_count'] ?? 0);
        if ($personalAttacks > 0) {
            $lines[] = "  {$personalAttacks} flagged as containing a personal attack, not just criticism of the work.";
        } else {
            $lines[] = "  None of the new items were flagged as personal attacks.";
        }

        $lines[] = "";
        $lines[] = "As always, nothing here shows you the actual review text — log in and reveal";
        $lines[] = "individual reviews only if and when you want to.";

        return implode("\n", $lines);
    }

    private function markSent(int $trackedTitleId): void
    {
        $stmt = Database::pdo()->prepare('UPDATE tracked_titles SET last_digest_sent_at = NOW() WHERE id = ?');
        $stmt->execute([$trackedTitleId]);
    }

    private function logNotification(int $userId, int $trackedTitleId, string $summary): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO notifications (user_id, tracked_title_id, type, sent_at, payload_summary)
             VALUES (?, ?, "digest", NOW(), ?)'
        );
        $stmt->execute([$userId, $trackedTitleId, $summary]);
    }
}
