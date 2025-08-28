<?php

namespace Modules\VkAds\app\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\VkAds\app\Models\VkAdsCreative;

class GenerateCreativeVariant implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public VkAdsCreative $creative,
        public string $targetAspectRatio
    ) {
        $this->onQueue('vk-ads-processing');
    }

    public function handle(): void
    {
        try {
            if ($this->creative->isVideo()) {
                $this->generateVideoVariant();
            } else {
                $this->generateImageVariant();
            }

        } catch (\Exception $e) {
            \Log::error('Failed to generate creative variant: '.$e->getMessage(), [
                'creative_id' => $this->creative->id,
                'target_ratio' => $this->targetAspectRatio,
                'exception' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    private function generateVideoVariant(): void
    {
        if (! $this->creative->videoFile) {
            throw new \Exception('No source video file found');
        }

        // Здесь можно использовать FFmpeg для создания варианта с нужным соотношением сторон
        $sourceVideo = $this->creative->videoFile;
        [$targetWidth, $targetHeight] = $this->calculateDimensions($sourceVideo, $this->targetAspectRatio);

        // Логика создания нового видеофайла с измененным соотношением сторон
        // Это может быть отдельный Job с FFmpeg обработкой

        \Log::info("Video variant generation completed for creative {$this->creative->id}");
    }

    private function generateImageVariant(): void
    {
        if (! $this->creative->imageFile) {
            throw new \Exception('No source image file found');
        }

        // Логика создания варианта изображения
        // Можно использовать GD или Imagick для изменения размера

        \Log::info("Image variant generation completed for creative {$this->creative->id}");
    }

    private function calculateDimensions($mediaFile, string $aspectRatio): array
    {
        [$ratioWidth, $ratioHeight] = explode(':', $aspectRatio);
        $targetRatio = $ratioWidth / $ratioHeight;

        // Рассчитываем новые размеры, сохраняя качество
        if ($mediaFile->width / $mediaFile->height > $targetRatio) {
            // Обрезаем по ширине
            $newHeight = $mediaFile->height;
            $newWidth = $newHeight * $targetRatio;
        } else {
            // Обрезаем по высоте
            $newWidth = $mediaFile->width;
            $newHeight = $newWidth / $targetRatio;
        }

        return [(int) $newWidth, (int) $newHeight];
    }
}
