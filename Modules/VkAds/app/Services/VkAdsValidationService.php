<?php

namespace Modules\VkAds\app\Services;

use App\Models\ImageFile;
use App\Models\VideoFile;

class VkAdsValidationService
{
    /**
     * Валидация требований VK Ads для разных форматов
     */
    public function validateVideoForFormat(VideoFile $video, string $format, string $aspectRatio = '16:9'): array
    {
        $errors = [];
        $requirements = $this->getVideoRequirements($format, $aspectRatio);

        // Проверяем соотношение сторон
        $videoAspectRatio = $video->width / $video->height;
        $expectedRatio = $this->parseAspectRatio($aspectRatio);

        if (abs($videoAspectRatio - $expectedRatio) > 0.1) {
            $errors[] = "Неверное соотношение сторон. Ожидается: {$aspectRatio}";
        }

        // Проверяем разрешение
        if ($video->width < $requirements['min_width'] || $video->height < $requirements['min_height']) {
            $errors[] = "Минимальное разрешение: {$requirements['min_width']}x{$requirements['min_height']}";
        }

        // Проверяем длительность
        if ($video->duration > $requirements['max_duration']) {
            $errors[] = "Максимальная длительность: {$requirements['max_duration']} секунд";
        }

        if ($video->duration < $requirements['min_duration']) {
            $errors[] = "Минимальная длительность: {$requirements['min_duration']} секунд";
        }

        // Проверяем размер файла
        if ($video->size > $requirements['max_file_size']) {
            $errors[] = 'Максимальный размер файла: '.$this->formatBytes($requirements['max_file_size']);
        }

        return $errors;
    }

    public function validateImageForFormat(ImageFile $image, string $format, string $aspectRatio = '1:1'): array
    {
        $errors = [];
        $requirements = $this->getImageRequirements($format, $aspectRatio);

        // Проверяем соотношение сторон
        $imageAspectRatio = $image->width / $image->height;
        $expectedRatio = $this->parseAspectRatio($aspectRatio);

        if (abs($imageAspectRatio - $expectedRatio) > 0.1) {
            $errors[] = "Неверное соотношение сторон. Ожидается: {$aspectRatio}";
        }

        // Проверяем разрешение
        if ($image->width < $requirements['min_width'] || $image->height < $requirements['min_height']) {
            $errors[] = "Минимальное разрешение: {$requirements['min_width']}x{$requirements['min_height']}";
        }

        // Проверяем размер файла
        if ($image->size > $requirements['max_file_size']) {
            $errors[] = 'Максимальный размер файла: '.$this->formatBytes($requirements['max_file_size']);
        }

        return $errors;
    }

    /**
     * Получить требования для видео
     */
    private function getVideoRequirements(string $format, string $aspectRatio): array
    {
        $baseRequirements = [
            'min_width' => 640,
            'min_height' => 360,
            'max_file_size' => 90 * 1024 * 1024, // 90 MB
            'min_duration' => 5,
            'max_duration' => 180,
        ];

        // Специфичные требования для instream
        if ($format === 'instream') {
            $baseRequirements['max_duration'] = 30; // Instream до 30 секунд

            if ($aspectRatio === '16:9') {
                $baseRequirements['min_width'] = 1280;
                $baseRequirements['min_height'] = 720;
            }
        }

        return $baseRequirements;
    }

    /**
     * Получить требования для изображений
     */
    private function getImageRequirements(string $format, string $aspectRatio): array
    {
        $baseRequirements = [
            'min_width' => 600,
            'min_height' => 600,
            'max_file_size' => 10 * 1024 * 1024, // 10 MB
        ];

        // Специфичные требования для разных форматов
        switch ($format) {
            case 'banner':
                if ($aspectRatio === '16:9') {
                    $baseRequirements['min_width'] = 1200;
                    $baseRequirements['min_height'] = 675;
                }
                break;

            case 'native':
                $baseRequirements['min_width'] = 600;
                $baseRequirements['min_height'] = 600;
                break;
        }

        return $baseRequirements;
    }

    private function parseAspectRatio(string $ratio): float
    {
        [$width, $height] = explode(':', $ratio);

        return (float) $width / (float) $height;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1).' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return $bytes.' bytes';
    }
}
