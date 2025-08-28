<?php

namespace Modules\VkAds\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ImageFile;
use App\Models\VideoFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\VkAds\app\Http\Requests\CreateImageCreativeRequest;
use Modules\VkAds\app\Http\Requests\CreateVideoCreativeRequest;
use Modules\VkAds\app\Services\VkAdsAccountService;
use Modules\VkAds\app\Services\VkAdsCreativeService;
use OpenApi\Annotations as OA;

class VkAdsCreativeController extends Controller
{
    public function __construct(
        private VkAdsCreativeService $creativeService,
        private VkAdsAccountService $accountService
    ) {}

    /**
     * Получить список креативов
     *
     * @OA\Get(
     *     path="/api/vk-ads/accounts/{accountId}/creatives",
     *     tags={"VkAds/Creatives"},
     *     summary="Получить креативы аккаунта",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\Parameter(
     *         name="accountId",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Parameter(
     *         name="creative_type",
     *         in="query",
     *
     *         @OA\Schema(type="string", enum={"image", "video", "html5", "carousel"})
     *     ),
     *
     *     @OA\Parameter(
     *         name="format",
     *         in="query",
     *
     *         @OA\Schema(type="string", enum={"banner", "instream", "native", "interstitial"})
     *     ),
     *
     *     @OA\Response(response=200, description="Список креативов")
     * )
     */
    public function index(int $accountId, Request $request): JsonResponse
    {
        $account = $this->accountService->getAccounts()->where('id', $accountId)->firstOrFail();

        $filters = $request->only(['creative_type', 'format', 'moderation_status']);
        $creatives = $this->creativeService->getCreatives($account, $filters);

        return response()->json($creatives);
    }

    /**
     * Создать видео креатив с вариантами
     *
     * @OA\Post(
     *     path="/api/vk-ads/accounts/{accountId}/creatives/video",
     *     tags={"VkAds/Creatives"},
     *     summary="Создать видео креатив",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"name", "primary_video_file_id"},
     *
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="format", type="string", enum={"banner", "instream", "native"}),
     *             @OA\Property(property="primary_video_file_id", type="integer"),
     *             @OA\Property(property="variant_video_files", type="object",
     *                 description="Варианты видео для разных соотношений сторон",
     *                 @OA\Property(property="9:16", type="integer", description="ID видеофайла 9:16"),
     *                 @OA\Property(property="1:1", type="integer", description="ID видеофайла 1:1")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Креатив создан"),
     *     @OA\Response(response=422, description="Ошибка валидации")
     * )
     */
    public function createVideoCreative(int $accountId, CreateVideoCreativeRequest $request): JsonResponse
    {
        $account = $this->accountService->getAccounts()->where('id', $accountId)->firstOrFail();

        $primaryVideo = VideoFile::findOrFail($request->input('primary_video_file_id'));

        // Получаем варианты видео
        $variants = [];
        $variantVideoFiles = $request->input('variant_video_files', []);

        foreach ($variantVideoFiles as $aspectRatio => $videoFileId) {
            $videoFile = VideoFile::find($videoFileId);
            if ($videoFile) {
                $variants[$aspectRatio] = $videoFile;
            }
        }

        $creative = $this->creativeService->createVideoCreativeSet(
            $account,
            $primaryVideo,
            $variants,
            $request->only(['name', 'description', 'format'])
        );

        return response()->json($creative->load(['videoFile', 'account']), 201);
    }

    /**
     * Создать изображение креатив с вариантами
     *
     * @OA\Post(
     *     path="/api/vk-ads/accounts/{accountId}/creatives/image",
     *     tags={"VkAds/Creatives"},
     *     summary="Создать креатив из изображения",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"name", "primary_image_file_id"},
     *
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="format", type="string", enum={"banner", "native", "interstitial"}),
     *             @OA\Property(property="primary_image_file_id", type="integer"),
     *             @OA\Property(property="variant_image_files", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Креатив создан")
     * )
     */
    public function createImageCreative(int $accountId, CreateImageCreativeRequest $request): JsonResponse
    {
        $account = $this->accountService->getAccounts()->where('id', $accountId)->firstOrFail();

        $primaryImage = ImageFile::findOrFail($request->input('primary_image_file_id'));

        // Получаем варианты изображений
        $variants = [];
        $variantImageFiles = $request->input('variant_image_files', []);

        foreach ($variantImageFiles as $aspectRatio => $imageFileId) {
            $imageFile = ImageFile::find($imageFileId);
            if ($imageFile) {
                $variants[$aspectRatio] = $imageFile;
            }
        }

        $creative = $this->creativeService->createImageCreativeSet(
            $account,
            $primaryImage,
            $variants,
            $request->only(['name', 'description', 'format'])
        );

        return response()->json($creative->load(['imageFile', 'account']), 201);
    }

    /**
     * Получить креатив
     */
    public function show(int $accountId, int $creativeId): JsonResponse
    {
        $creative = \Modules\VkAds\app\Models\VkAdsCreative::with([
            'videoFile', 'imageFile', 'ads',
        ])->findOrFail($creativeId);

        return response()->json($creative);
    }

    /**
     * Обновить креатив
     */
    public function update(int $accountId, int $creativeId, Request $request): JsonResponse
    {
        $creative = $this->creativeService->updateCreative($creativeId, $request->validated());

        return response()->json($creative);
    }

    /**
     * Удалить креатив
     */
    public function destroy(int $accountId, int $creativeId): JsonResponse
    {
        $this->creativeService->deleteCreative($creativeId);

        return response()->json(['message' => 'Creative deleted successfully']);
    }

    /**
     * Получить instream креативы
     *
     * @OA\Get(
     *     path="/api/vk-ads/accounts/{accountId}/creatives/instream",
     *     tags={"VkAds/Creatives"},
     *     summary="Получить instream креативы",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\Response(response=200, description="Список instream креативов")
     * )
     */
    public function getInstreamCreatives(int $accountId): JsonResponse
    {
        $account = $this->accountService->getAccounts()->where('id', $accountId)->firstOrFail();
        $creatives = $this->creativeService->getInstreamCreatives($account);

        return response()->json($creatives);
    }

    /**
     * Валидировать видео для instream
     *
     * @OA\Post(
     *     path="/api/vk-ads/creatives/validate-instream",
     *     tags={"VkAds/Creatives"},
     *     summary="Валидировать видео для instream рекламы",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"video_files"},
     *
     *             @OA\Property(property="video_files", type="object",
     *                 @OA\Property(property="16:9", type="integer", description="ID видеофайла 16:9"),
     *                 @OA\Property(property="9:16", type="integer", description="ID видеофайла 9:16"),
     *                 @OA\Property(property="1:1", type="integer", description="ID видеофайла 1:1")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="Результат валидации")
     * )
     */
    public function validateInstreamVideos(Request $request): JsonResponse
    {
        $videoFileIds = $request->input('video_files', []);
        $videoFiles = [];

        foreach ($videoFileIds as $aspectRatio => $fileId) {
            $videoFile = VideoFile::find($fileId);
            if ($videoFile) {
                $videoFiles[$aspectRatio] = $videoFile;
            }
        }

        $errors = $this->creativeService->validateInstreamVideoSet($videoFiles);

        return response()->json([
            'valid' => empty($errors),
            'errors' => $errors,
            'recommendations' => $this->getInstreamRecommendations($videoFiles),
        ]);
    }

    /**
     * Синхронизировать креатив с VK
     */
    public function sync(int $accountId, int $creativeId): JsonResponse
    {
        $creative = \Modules\VkAds\app\Models\VkAdsCreative::findOrFail($creativeId);
        $syncedCreative = $this->creativeService->syncCreativeFromVk($creative->vk_creative_id);

        return response()->json($syncedCreative);
    }

    private function getInstreamRecommendations(array $videoFiles): array
    {
        $recommendations = [];

        if (! isset($videoFiles['16:9'])) {
            $recommendations[] = 'Добавьте видео 16:9 для основного instream размещения';
        }

        if (! isset($videoFiles['9:16'])) {
            $recommendations[] = 'Добавьте вертикальное видео 9:16 для мобильных Stories';
        }

        if (! isset($videoFiles['1:1'])) {
            $recommendations[] = 'Добавьте квадратное видео 1:1 для ленты социальных сетей';
        }

        return $recommendations;
    }
}
