<?php

namespace App\Console\Commands;

use App\Models\PostMedia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GenerateVideoThumbnails extends Command
{
    protected $signature = 'media:generate-thumbnails';
    protected $description = 'Generate thumbnails for existing videos that have no thumbnail';

    public function handle(): int
    {
        $videos = PostMedia::where('type', 'video')
            ->whereNull('thumbnail_url')
            ->get();

        if ($videos->isEmpty()) {
            $this->info('No videos without thumbnails found.');
            return 0;
        }

        $this->info("Found {$videos->count()} videos without thumbnails.");

        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $bar = $this->output->createProgressBar($videos->count());
        $bar->start();

        $success = 0;
        $failed = 0;

        foreach ($videos as $media) {
            try {
                $originalUrl = $media->getRawOriginal('url');

                // Download video from S3 to temp
                $videoContent = Storage::disk('s3')->get($originalUrl);
                $tempVideo = $tempDir . '/' . Str::random(20) . '.mp4';
                file_put_contents($tempVideo, $videoContent);

                // Generate thumbnail using FFmpeg (first frame at 1 second)
                $tempThumb = $tempDir . '/' . Str::random(40) . '.jpg';
                $cmd = "ffmpeg -i {$tempVideo} -ss 00:00:01 -vframes 1 -q:v 2 -y {$tempThumb} 2>/dev/null";
                exec($cmd, $output, $returnCode);

                // If 1 second fails (video too short), try 0 seconds
                if ($returnCode !== 0 || !file_exists($tempThumb)) {
                    $cmd = "ffmpeg -i {$tempVideo} -ss 00:00:00 -vframes 1 -q:v 2 -y {$tempThumb} 2>/dev/null";
                    exec($cmd, $output, $returnCode);
                }

                if ($returnCode === 0 && file_exists($tempThumb)) {
                    // Upload thumbnail to S3
                    $thumbPath = 'thumbnails/' . Str::random(40) . '.jpg';
                    Storage::disk('s3')->put($thumbPath, file_get_contents($tempThumb));

                    // Update database
                    $media->update(['thumbnail_url' => $thumbPath]);
                    $success++;
                } else {
                    $this->warn("\nFailed to generate thumbnail for media ID: {$media->id}");
                    $failed++;
                }

                // Cleanup temp files
                @unlink($tempVideo);
                @unlink($tempThumb);
            } catch (\Exception $e) {
                $this->error("\nError for media ID {$media->id}: {$e->getMessage()}");
                $failed++;
                @unlink($tempVideo ?? '');
                @unlink($tempThumb ?? '');
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done! Success: {$success}, Failed: {$failed}");

        // Cleanup temp directory
        @rmdir($tempDir);

        return 0;
    }
}
