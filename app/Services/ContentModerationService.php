<?php

namespace App\Services;

use Aws\Rekognition\RekognitionClient;
use Illuminate\Http\UploadedFile;

class ContentModerationService
{
    // ─────────────────────────────────────────
    // BAD WORDS FILTER (Layer 1 - Free)
    // ─────────────────────────────────────────
    private static array $blockedWords = [
        'porn', 'xxx', 'nude', 'naked', 'sex', 'fuck', 'shit', 'dick', 'pussy',
        'ass', 'bitch', 'nigger', 'nigga', 'kill yourself', 'suicide', 'rape',
        'molest', 'pedophile', 'child abuse', 'terrorism', 'bomb threat',
    ];

    public static function checkText(?string $content): array
    {
        if (!$content) {
            return ['safe' => true, 'reason' => null];
        }

        $lower = strtolower($content);

        foreach (self::$blockedWords as $word) {
            if (str_contains($lower, $word)) {
                return [
                    'safe'   => false,
                    'reason' => 'Content contains inappropriate language.',
                ];
            }
        }

        return ['safe' => true, 'reason' => null];
    }

    // ─────────────────────────────────────────
    // IMAGE MODERATION via AWS Rekognition (Layer 2)
    // ─────────────────────────────────────────
    public static function checkImage(UploadedFile $file): array
    {
        if (!config('services.rekognition.enabled', false)) {
            return ['safe' => true, 'reason' => null, 'labels' => []];
        }

        try {
            $client = new RekognitionClient([
                'region'      => config('services.rekognition.region', config('filesystems.disks.s3.region')),
                'version'     => 'latest',
                'credentials' => [
                    'key'    => config('filesystems.disks.s3.key'),
                    'secret' => config('filesystems.disks.s3.secret'),
                ],
            ]);

            $result = $client->detectModerationLabels([
                'Image' => [
                    'Bytes' => file_get_contents($file->getPathname()),
                ],
                'MinConfidence' => 70,
            ]);

            $labels = $result->get('ModerationLabels');

            if (!empty($labels)) {
                $flaggedCategories = [];
                foreach ($labels as $label) {
                    $flaggedCategories[] = $label['Name'] . ' (' . round($label['Confidence']) . '%)';
                }

                return [
                    'safe'   => false,
                    'reason' => 'Image flagged: ' . implode(', ', $flaggedCategories),
                    'labels' => $labels,
                ];
            }

            return ['safe' => true, 'reason' => null, 'labels' => []];
        } catch (\Exception $e) {
            // If Rekognition fails, allow the post but log the error
            \Log::warning('Rekognition moderation failed: ' . $e->getMessage());
            return ['safe' => true, 'reason' => null, 'labels' => []];
        }
    }

    // ─────────────────────────────────────────
    // CHECK ALL MEDIA FILES
    // ─────────────────────────────────────────
    public static function checkMedia(array $files): array
    {
        foreach ($files as $file) {
            $ext = strtolower($file->getClientOriginalExtension());

            // Only scan images (video scanning requires async processing)
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $result = self::checkImage($file);
                if (!$result['safe']) {
                    return $result;
                }
            }
        }

        return ['safe' => true, 'reason' => null];
    }
}
