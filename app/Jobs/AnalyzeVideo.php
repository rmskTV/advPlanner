<?php

namespace App\Jobs;

use App\Models\VideoFile;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class AnalyzeVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public VideoFile $videoFile)
    {
        $this->onQueue('AnalyzeVideo');
    }

    /**
     * @throws Exception
     */
    public function handle(): void
    {

        try {
            $this->videoFile->update(['status' => 'analyzing']);

            $filePath = Storage::disk('public')->path($this->videoFile->original_file_location);
            $analysis = $this->analyzeWithFfprobe($filePath);

            $this->videoFile->update([
                'status' => 'converting',
                'media_info' => json_encode($analysis['metadata']),
                'width' => $analysis['video']['width'] ?? null,
                'height' => $analysis['video']['height'] ?? null,
                'duration' => $analysis['format']['duration'] ?? null,
                'size' => $analysis['format']['size'] ?? null,
            ]);

            // Генерация превью-версии в VP9
            $convertedPath = $this->convertToVp9($filePath);

            $this->videoFile->update([
                'status' => 'done',
                'preview_file_location' => $convertedPath,
            ]);

        } catch (\Exception $e) {
            logger()->error('Video analysis failed', [
                'error' => $e->getMessage(),
                'file' => $this->videoFile->id,
            ]);
            $this->videoFile->update(['status' => 'fail_analyzing']);
            throw $e;
        }
    }

    protected function analyzeWithFfprobe(string $filePath): array
    {
        $process = new Process([
            'ffprobe',
            '-v', 'error',
            '-show_streams',
            '-show_format',
            '-of', 'json',
            $filePath,
        ]);

        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $data = json_decode($process->getOutput(), true);

        return [
            'metadata' => $data,
            'format' => $data['format'] ?? [],
            'video' => collect($data['streams'] ?? [])->firstWhere('codec_type', 'video') ?? [],
        ];
    }

    protected function convertToVp9(string $sourcePath): string
    {
        $convertedName = 'preview_'.Str::random(16).'.webm';
        $convertedPath = 'videos/previews/'.Carbon::now()->format('Y/m/').$convertedName;
        $outputPath = Storage::disk('public')->path($convertedPath);

        // Создаем директорию, если ее нет
        Storage::disk('public')->makeDirectory(dirname($convertedPath));

        $process = new Process([
            'ffmpeg',
            '-i', $sourcePath,
            '-c:v', 'libvpx-vp9',
            '-b:v', '1M',
            '-crf', '31',
            '-vf', 'scale=-1:720',
            '-c:a', 'libopus',
            '-b:a', '128k',
            '-f', 'webm',
            '-threads', '4',
            '-speed', '2',
            '-row-mt', '1',
            '-y',
            $outputPath,
        ]);

        $process->setTimeout(3600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $convertedPath;
    }
}
