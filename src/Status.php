<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * The per-post status meta (section 6).
 */
final class Status
{
    public const TRIED_KEPT = 20;

    /**
     * @return array<string, mixed>
     */
    public static function get(int $postId): array
    {
        $value = get_post_meta($postId, Meta::STATUS, true);
        return is_array($value) ? $value : [];
    }

    public static function write(RunRecord $record): void
    {
        if ($record->postId <= 0 || $record->dryRun) {
            return;
        }
        $tried = [];
        foreach (array_slice($record->tried, 0, self::TRIED_KEPT) as $item) {
            $tried[] = [
                'url' => Url::bare((string) ($item['candidate']['url'] ?? '')),
                'kind' => (string) ($item['candidate']['kind'] ?? ''),
                'reason' => $item['reason'],
            ];
        }
        update_post_meta($record->postId, Meta::STATUS, [
            'outcome' => $record->outcome,
            'reason' => $record->reason,
            'attempt' => $record->attempt,
            'at' => $record->finishedAt,
            'attachment_id' => $record->attachmentId,
            'kind' => $record->winner->kind ?? '',
            'image_url' => $record->winner->url ?? '',
            'source_url' => $record->sourceUrl,
            'tried' => $tried,
        ]);
    }
}
