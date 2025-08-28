<?php

namespace Modules\VkAds\app\Services;

use App\Models\ImageFile;
use App\Models\VideoFile;
use Modules\VkAds\app\Models\VkAdsAccount;
use Modules\VkAds\app\Models\VkAdsCreative;

class VkAdsCreativeService
{
    private VkAdsApiService $apiService;

    public function __construct(VkAdsApiService $apiService)
    {
        $this->apiService = $apiService;
    }

    // === СОЗДАНИЕ КРЕАТИВОВ ===

    public function createVideoCreativeSet(VkAdsAccount $account, VideoFile $primaryVideo, array $variants, array $creativeData): VkAdsCreative
    {
        // Загружаем основное видео в VK Ads
        $vkResponse = $this->apiService->makeAuthenticatedRequest($account, 'creatives.upload', [
            'account_id' => $account->vk_account_id,
            'type' => 'video',
            'name' => $creativeData['name'],
            'video_url' => $primaryVideo->getPublicUrl(),
        ]);

        // Подготавливаем варианты медиафайлов
        $mediaVariants = [];
        foreach ($variants as $aspectRatio => $videoFile) {
            if ($videoFile instanceof VideoFile) {
                $mediaVariants[] = [
                    'aspect_ratio' => $aspectRatio,
                    'type' => 'video',
                    'video_file_id' => $videoFile->id,
                    'width' => $videoFile->width,
                    'height' => $videoFile->height,
                    'duration' => $videoFile->duration,
                    'url' => $videoFile->getPublicUrl(),
                ];
            }
        }

        return VkAdsCreative::create([
            'vk_creative_id' => $vkResponse['creative_id'],
            'vk_ads_account_id' => $account->id,
            'video_file_id' => $primaryVideo->id,
            'name' => $creativeData['name'],
            'description' => $creativeData['description'] ?? null,
            'creative_type' => VkAdsCreative::TYPE_VIDEO,
            'format' => $creativeData['format'] ?? VkAdsCreative::FORMAT_INSTREAM,
            'width' => $primaryVideo->width,
            'height' => $primaryVideo->height,
            'duration' => $primaryVideo->duration,
            'file_size' => $primaryVideo->size,
            'media_variants' => $mediaVariants,
            'moderation_status' => 'pending',
            'vk_data' => $vkResponse,
        ]);
    }

    public function createImageCreativeSet(VkAdsAccount $account, ImageFile $primaryImage, array $variants, array $creativeData): VkAdsCreative
    {
        // Загружаем основное изображение в VK Ads
        $vkResponse = $this->apiService->makeAuthenticatedRequest($account, 'creatives.upload', [
            'account_id' => $account->vk_account_id,
            'type' => 'image',
            'name' => $creativeData['name'],
            'image_url' => $primaryImage->getPublicUrl(),
        ]);

        // Подготавливаем варианты изображений
        $mediaVariants = [];
        foreach ($variants as $aspectRatio => $imageFile) {
            if ($imageFile instanceof ImageFile) {
                $mediaVariants[] = [
                    'aspect_ratio' => $aspectRatio,
                    'type' => 'image',
                    'image_file_id' => $imageFile->id,
                    'width' => $imageFile->width,
                    'height' => $imageFile->height,
                    'url' => $imageFile->getPublicUrl(),
                ];
            }
        }

        return VkAdsCreative::create([
            'vk_creative_id' => $vkResponse['creative_id'],
            'vk_ads_account_id' => $account->id,
            'image_file_id' => $primaryImage->id,
            'name' => $creativeData['name'],
            'description' => $creativeData['description'] ?? null,
            'creative_type' => VkAdsCreative::TYPE_IMAGE,
            'format' => $creativeData['format'] ?? VkAdsCreative::FORMAT_BANNER,
            'width' => $primaryImage->width,
            'height' => $primaryImage->height,
            'file_size' => $primaryImage->size,
            'media_variants' => $mediaVariants,
            'moderation_status' => 'pending',
            'vk_data' => $vkResponse,
        ]);
    }

    // === ПОЛУЧЕНИЕ МЕДИАФАЙЛОВ ДЛЯ КРЕАТИВА ===

    public function getMediaFileForAspectRatio(VkAdsCreative $creative, string $aspectRatio): mixed
    {
        // Сначала ищем в вариантах
        $variant = $creative->getVariantForAspectRatio($aspectRatio);

        if ($variant) {
            if ($variant['type'] === 'video' && isset($variant['video_file_id'])) {
                return VideoFile::find($variant['video_file_id']);
            }
            if ($variant['type'] === 'image' && isset($variant['image_file_id'])) {
                return ImageFile::find($variant['image_file_id']);
            }
        }

        // Если нет подходящего варианта, возвращаем основной файл
        return $creative->isVideo() ? $creative->videoFile : $creative->imageFile;
    }

    // === ВАЛИДАЦИЯ ===

    public function validateInstreamVideoSet(array $videoFiles): array
    {
        $errors = [];
        $requiredRatios = ['16:9']; // Обязательное соотношение для instream
        $optionalRatios = ['9:16', '1:1']; // Дополнительные варианты

        foreach ($requiredRatios as $ratio) {
            if (! isset($videoFiles[$ratio])) {
                $errors[] = "Отсутствует обязательное видео с соотношением сторон {$ratio} для instream рекламы";

                continue;
            }

            $videoFile = $videoFiles[$ratio];
            $aspectRatio = $videoFile->width / $videoFile->height;
            $expectedRatio = $this->parseAspectRatio($ratio);

            if (abs($aspectRatio - $expectedRatio) > 0.1) {
                $errors[] = "Видео {$ratio} имеет неверное соотношение сторон";
            }

            if ($videoFile->duration > 30) {
                $errors[] = "Длительность видео {$ratio} превышает 30 секунд для instream";
            }
        }

        return $errors;
    }

    private function parseAspectRatio(string $ratio): float
    {
        [$width, $height] = explode(':', $ratio);

        return (float) $width / (float) $height;
    }
}
