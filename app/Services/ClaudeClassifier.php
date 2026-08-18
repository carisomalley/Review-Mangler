<?php

namespace App\Services;

use App\Env;

/**
 * The classification pipeline described in CLAUDE.md §7.3. Scores one piece
 * of review/write-up text against a fixed rubric. The rubric text below is
 * RUBRIC_VERSION-tagged on purpose — bump the version string if you edit the
 * prompt, so stored classifications stay traceable to the rubric that
 * produced them (§7.3, §9.4).
 */
class ClaudeClassifier
{
    public const RUBRIC_VERSION = 'v1-2026-08';

    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are scoring a single review or write-up of a creative work (a book or
film) for a tool that helps the work's creator understand feedback without
being blindsided by cruelty. Score ONLY the text given. Be precise and
conservative — do not infer things the text doesn't support.

Score these fields:

1. sentiment: "positive", "negative", or "mixed" — the reviewer's overall
   stance toward the WORK ITSELF (not toward the creator as a person).

2. meanness_score: integer 1-5.
   1 = respectful in tone even if harshly critical of the work.
   2 = a bit dismissive or snarky, but not cruel.
   3 = openly contemptuous or mocking in tone.
   4 = insulting, deliberately belittling.
   5 = gratuitously cruel, designed to humiliate.
   Judge TONE, independent of how negative the opinion is — a scathing but
   respectful pan of the work is low meanness; a gushing review that still
   contains one cruel personal jab is high meanness.

3. constructive: true if the review contains specific, actionable feedback
   about the work (what worked, what didn't, and why) that the creator could
   learn from — regardless of tone or overall sentiment. false if it's pure
   reaction/vibes with no specific substance ("this sucked", "loved it").

4. personal_attack: true ONLY if the text targets the CREATOR as a person —
   their appearance, body, disability, identity, character, motives — rather
   than critiquing the work. This is the single most important field: do not
   mark true for harsh criticism of the work itself, even if worded bluntly.
   Mark true even if the personal remark is brief, if it is present.

5. content_tags: an array of zero or more strings from this exact set only —
   "appearance_or_body", "disability", "profanity", "spoilers",
   "identity_based" — include a tag only if the text actually contains that
   kind of content. Empty array if none apply.

Respond with ONLY a single JSON object, no other text, matching exactly:
{"sentiment": "...", "meanness_score": N, "constructive": true|false,
 "personal_attack": true|false, "content_tags": ["..."]}
PROMPT;

    /**
     * @return array{sentiment:string, meanness_score:int, constructive:bool, personal_attack:bool, content_tags:array<int,string>}
     */
    public function classify(string $workTitle, string $reviewText): array
    {
        $apiKey = Env::require('CLAUDE_API_KEY');
        $model = Env::require('CLAUDE_MODEL');

        $userMessage = "Work being reviewed: {$workTitle}\n\nReview/write-up text:\n" . $reviewText;

        $payload = [
            'model' => $model,
            'max_tokens' => 300,
            'system' => self::SYSTEM_PROMPT,
            'messages' => [
                ['role' => 'user', 'content' => $userMessage],
            ],
        ];

        $response = HttpClient::postJson(self::API_URL, $payload, [
            "x-api-key: $apiKey",
            'anthropic-version: 2023-06-01',
        ]);

        if ($response['status'] !== 200) {
            throw new \RuntimeException('Claude API call failed with HTTP ' . $response['status'] . ': ' . $response['body']);
        }

        $data = json_decode($response['body'], true);
        $text = $data['content'][0]['text'] ?? '';

        return $this->parseResult($text);
    }

    /**
     * @return array{sentiment:string, meanness_score:int, constructive:bool, personal_attack:bool, content_tags:array<int,string>}
     */
    private function parseResult(string $text): array
    {
        // Be tolerant of the model wrapping the JSON in prose or a code fence,
        // even though the prompt asks for JSON only.
        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $text = $matches[0];
        }

        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            throw new \RuntimeException('Could not parse classification JSON from model output: ' . $text);
        }

        $allowedTags = ['appearance_or_body', 'disability', 'profanity', 'spoilers', 'identity_based'];
        $tags = array_values(array_intersect((array) ($parsed['content_tags'] ?? []), $allowedTags));

        $sentiment = $parsed['sentiment'] ?? 'mixed';
        if (!in_array($sentiment, ['positive', 'negative', 'mixed'], true)) {
            $sentiment = 'mixed';
        }

        return [
            'sentiment' => $sentiment,
            'meanness_score' => max(1, min(5, (int) ($parsed['meanness_score'] ?? 1))),
            'constructive' => (bool) ($parsed['constructive'] ?? false),
            'personal_attack' => (bool) ($parsed['personal_attack'] ?? false),
            'content_tags' => $tags,
        ];
    }
}
