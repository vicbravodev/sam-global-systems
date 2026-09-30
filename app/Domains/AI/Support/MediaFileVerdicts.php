<?php

namespace App\Domains\AI\Support;

use App\Domains\AI\Enums\MediaAssessmentResult;
use App\Domains\AI\Models\AIMediaAssessment;
use Illuminate\Support\Collection;

/**
 * One AI verdict per file a person sees. The vision model assesses photos
 * and the frames cut out of each clip, so a clip's verdict is its frames'.
 *
 * Per media row the latest assessment wins (a re-evaluation supersedes the
 * previous one); across a clip's frames a verdict that affirms something
 * (confirms/contradicts) outranks doubt, so "inconclusive, inconclusive,
 * contradicts" reads as "contradicts" — the frame that saw something.
 */
final class MediaFileVerdicts
{
    /**
     * @param  Collection<int, AIMediaAssessment>  $assessments  any order, any evaluation version
     * @param  array<int, list<int>>  $filesToMediaIds  file id => [file id, ...frame ids]
     * @return array<int, AIMediaAssessment> file id => verdict (files never assessed are absent)
     */
    public static function forFiles(Collection $assessments, array $filesToMediaIds): array
    {
        $latestPerMedia = $assessments
            ->sortByDesc(fn (AIMediaAssessment $a): string => ($a->assessed_at?->toIso8601String() ?? '').'#'.str_pad((string) $a->id, 12, '0', STR_PAD_LEFT))
            ->unique('event_media_context_id')
            ->keyBy('event_media_context_id');

        $verdicts = [];

        foreach ($filesToMediaIds as $fileId => $mediaIds) {
            $best = null;

            foreach ($mediaIds as $mediaId) {
                $candidate = $latestPerMedia->get($mediaId);

                if ($candidate !== null && ($best === null || self::weight($candidate) > self::weight($best))) {
                    $best = $candidate;
                }
            }

            if ($best !== null) {
                $verdicts[$fileId] = $best;
            }
        }

        return $verdicts;
    }

    public static function weight(AIMediaAssessment $assessment): int
    {
        return match ($assessment->result) {
            MediaAssessmentResult::ConfirmsEvent, MediaAssessmentResult::ContradictsEvent => 2,
            MediaAssessmentResult::Inconclusive, MediaAssessmentResult::LowQuality => 1,
            default => 0,
        };
    }
}
