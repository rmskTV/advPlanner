<?php

namespace Modules\VkAds\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\VkAds\app\DTOs\CreateAdDTO;
use Modules\VkAds\app\DTOs\CreateInstreamAdDTO;
use Modules\VkAds\app\Http\Requests\CreateAdRequest;
use Modules\VkAds\app\Http\Requests\CreateInstreamAdRequest;
use Modules\VkAds\app\Http\Requests\UpdateAdRequest;
use Modules\VkAds\app\Services\VkAdsAdGroupService;
use Modules\VkAds\app\Services\VkAdsAdService;
use OpenApi\Annotations as OA;

class VkAdsAdController extends Controller
{
    public function __construct(
        private VkAdsAdService $adService,
        private VkAdsAdGroupService $adGroupService
    ) {}

    /**
     * Получить объявления группы
     *
     * @OA\Get(
     *     path="/api/vk-ads/ad-groups/{adGroupId}/ads",
     *     tags={"VkAds/Ads"},
     *     summary="Получить объявления группы",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\Parameter(
     *         name="adGroupId",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *
     *         @OA\Schema(type="string", enum={"active", "paused", "deleted", "archived"})
     *     ),
     *
     *     @OA\Parameter(
     *         name="is_instream",
     *         in="query",
     *
     *         @OA\Schema(type="boolean")
     *     ),
     *
     *     @OA\Response(response=200, description="Список объявлений")
     * )
     */
    public function index(int $adGroupId, Request $request): JsonResponse
    {
        $adGroup = $this->adGroupService->getAdGroups($adGroupId)->firstOrFail();

        $filters = $request->only(['status', 'is_instream', 'moderation_status']);
        $ads = $this->adService->getAds($adGroup, $filters);

        return response()->json($ads);
    }

    /**
     * Создать instream объявление
     *
     * @OA\Post(
     *     path="/api/vk-ads/ad-groups/{adGroupId}/ads/instream",
     *     tags={"VkAds/Ads"},
     *     summary="Создать instream объявление",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"name", "creative_id", "headline", "description", "final_url"},
     *
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="creative_id", type="integer", description="ID креатива с видео вариантами"),
     *             @OA\Property(property="headline", type="string", maxLength=100),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="final_url", type="string", format="url"),
     *             @OA\Property(property="call_to_action", type="string"),
     *             @OA\Property(property="instream_position", type="string", enum={"preroll", "midroll", "postroll"}),
     *             @OA\Property(property="skippable", type="boolean"),
     *             @OA\Property(property="skip_offset", type="integer", description="Секунд до возможности пропустить")
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Instream объявление создано"),
     *     @OA\Response(response=422, description="Ошибка валидации")
     * )
     */
    public function createInstream(int $adGroupId, CreateInstreamAdRequest $request): JsonResponse
    {
        $adGroup = \Modules\VkAds\app\Models\VkAdsAdGroup::findOrFail($adGroupId);

        $dto = CreateInstreamAdDTO::fromRequest($request);
        $ad = $this->adService->createInstreamAdWithVariants($adGroup, $dto);

        return response()->json($ad->load(['creatives.videoFile', 'adGroup']), 201);
    }

    /**
     * Создать универсальное объявление
     *
     * @OA\Post(
     *     path="/api/vk-ads/ad-groups/{adGroupId}/ads/universal",
     *     tags={"VkAds/Ads"},
     *     summary="Создать универсальное объявление",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"name", "creative_ids", "headline", "description", "final_url"},
     *
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="creative_ids", type="array",
     *                 description="Массив ID креативов для разных форматов",
     *
     *                 @OA\Items(type="integer")
     *             ),
     *
     *             @OA\Property(property="headline", type="string"),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="final_url", type="string", format="url"),
     *             @OA\Property(property="call_to_action", type="string")
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Универсальное объявление создано")
     * )
     */
    public function createUniversal(int $adGroupId, CreateAdRequest $request): JsonResponse
    {
        $adGroup = \Modules\VkAds\app\Models\VkAdsAdGroup::findOrFail($adGroupId);

        $dto = CreateAdDTO::fromRequest($request);
        $creativeIds = $request->input('creative_ids');

        $ad = $this->adService->createUniversalAd($adGroup, $creativeIds, $dto);

        return response()->json($ad->load(['creatives', 'adGroup']), 201);
    }

    /**
     * Получить объявление
     */
    public function show(int $adGroupId, int $adId): JsonResponse
    {
        $ad = \Modules\VkAds\app\Models\VkAdsAd::with([
            'creatives.videoFile',
            'creatives.imageFile',
            'adGroup.campaign',
            'statistics' => function ($query) {
                $query->whereBetween('stats_date', [now()->subDays(30), now()]);
            },
        ])->findOrFail($adId);

        return response()->json($ad);
    }

    /**
     * Обновить объявление
     */
    public function update(int $adGroupId, int $adId, UpdateAdRequest $request): JsonResponse
    {
        $ad = $this->adService->updateAd($adId, $request->validated());

        return response()->json($ad);
    }

    /**
     * Удалить объявление
     */
    public function destroy(int $adGroupId, int $adId): JsonResponse
    {
        $this->adService->deleteAd($adId);

        return response()->json(['message' => 'Ad deleted successfully']);
    }

    /**
     * Управление статусом объявления
     *
     * @OA\Post(
     *     path="/api/vk-ads/ads/{adId}/status",
     *     tags={"VkAds/Ads"},
     *     summary="Изменить статус объявления",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"status"},
     *
     *             @OA\Property(property="status", type="string", enum={"active", "paused"})
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="Статус изменен")
     * )
     */
    public function updateStatus(int $adGroupId, int $adId, Request $request): JsonResponse
    {
        $status = $request->input('status');
        $ad = $this->adService->updateAdStatus($adId, $status);

        return response()->json($ad);
    }

    /**
     * Добавить креатив вариант к объявлению
     *
     * @OA\Post(
     *     path="/api/vk-ads/ads/{adId}/creatives",
     *     tags={"VkAds/Ads"},
     *     summary="Добавить креатив к объявлению",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"creative_id", "aspect_ratio"},
     *
     *             @OA\Property(property="creative_id", type="integer"),
     *             @OA\Property(property="aspect_ratio", type="string", enum={"16:9", "9:16", "1:1", "4:5"})
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="Креатив добавлен к объявлению")
     * )
     */
    public function addCreativeVariant(int $adGroupId, int $adId, Request $request): JsonResponse
    {
        $request->validate([
            'creative_id' => ['required', 'integer', 'exists:vk_ads_creatives,id'],
            'aspect_ratio' => ['required', 'string', 'in:16:9,9:16,1:1,4:5'],
        ]);

        $ad = \Modules\VkAds\app\Models\VkAdsAd::findOrFail($adId);
        $creative = \Modules\VkAds\app\Models\VkAdsCreative::findOrFail($request->input('creative_id'));

        $this->adService->addCreativeVariant($ad, $creative, $request->input('aspect_ratio'));

        return response()->json([
            'message' => 'Creative variant added successfully',
            'ad' => $ad->load('creatives'),
        ]);
    }

    /**
     * Удалить креатив вариант из объявления
     */
    public function removeCreativeVariant(int $adGroupId, int $adId, Request $request): JsonResponse
    {
        $request->validate([
            'aspect_ratio' => ['required', 'string', 'in:16:9,9:16,1:1,4:5'],
        ]);

        $ad = \Modules\VkAds\app\Models\VkAdsAd::findOrFail($adId);

        $this->adService->removeCreativeVariant($ad, $request->input('aspect_ratio'));

        return response()->json([
            'message' => 'Creative variant removed successfully',
            'ad' => $ad->load('creatives'),
        ]);
    }

    /**
     * Получить только instream объявления
     */
    public function getInstreamAds(int $adGroupId): JsonResponse
    {
        $adGroup = \Modules\VkAds\app\Models\VkAdsAdGroup::findOrFail($adGroupId);
        $ads = $this->adService->getInstreamAds($adGroup);

        return response()->json($ads);
    }

    /**
     * Создать A/B тест объявлений
     *
     * @OA\Post(
     *     path="/api/vk-ads/ad-groups/{adGroupId}/ads/ab-test",
     *     tags={"VkAds/Ads"},
     *     summary="Создать A/B тест объявлений",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"variations"},
     *
     *             @OA\Property(property="variations", type="array",
     *
     *                 @OA\Items(
     *
     *                     @OA\Property(property="name", type="string"),
     *                     @OA\Property(property="creative_id", type="integer"),
     *                     @OA\Property(property="headline", type="string"),
     *                     @OA\Property(property="description", type="string"),
     *                     @OA\Property(property="final_url", type="string")
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="A/B тест создан")
     * )
     */
    public function createAbTest(int $adGroupId, Request $request): JsonResponse
    {
        $adGroup = \Modules\VkAds\app\Models\VkAdsAdGroup::findOrFail($adGroupId);
        $variations = $request->input('variations');

        $ads = $this->adService->createAdVariations($adGroup, $variations);

        return response()->json([
            'message' => 'A/B test created successfully',
            'ads' => $ads->load('creatives'),
            'test_id' => 'ab_test_'.$adGroup->id.'_'.now()->timestamp,
        ], 201);
    }
}
