<?php

namespace Modules\VkAds\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\VkAds\app\DTOs\CreateAdGroupDTO;
use Modules\VkAds\app\Http\Requests\CreateAdGroupRequest;
use Modules\VkAds\app\Http\Requests\UpdateAdGroupRequest;
use Modules\VkAds\app\Services\VkAdsAdGroupService;
use OpenApi\Annotations as OA;

class VkAdsAdGroupController extends Controller
{
    public function __construct(
        private VkAdsAdGroupService $adGroupService
    ) {}

    /**
     * Получить группы объявлений кампании
     *
     * @OA\Get(
     *     path="/api/vk-ads/campaigns/{campaignId}/ad-groups",
     *     tags={"VkAds/AdGroups"},
     *     summary="Получить группы объявлений",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\Response(response=200, description="Список групп объявлений")
     * )
     */
    public function index(int $campaignId): JsonResponse
    {
        $adGroups = $this->adGroupService->getAdGroups($campaignId);

        return response()->json($adGroups);
    }

    /**
     * Создать группу объявлений
     *
     * @OA\Post(
     *     path="/api/vk-ads/campaigns/{campaignId}/ad-groups",
     *     tags={"VkAds/AdGroups"},
     *     summary="Создать группу объявлений",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"name", "customer_order_item_id"},
     *
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="customer_order_item_id", type="integer"),
     *             @OA\Property(property="bid", type="number"),
     *             @OA\Property(property="targeting", type="object"),
     *             @OA\Property(property="placements", type="array")
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Группа создана")
     * )
     */
    public function store(int $campaignId, CreateAdGroupRequest $request): JsonResponse
    {
        $dto = CreateAdGroupDTO::fromRequest($request);
        $adGroup = $this->adGroupService->createAdGroup($campaignId, $dto);

        return response()->json($adGroup, 201);
    }

    /**
     * Получить группу объявлений
     */
    public function show(int $campaignId, int $adGroupId): JsonResponse
    {
        $adGroup = $this->adGroupService->getAdGroups($campaignId)
            ->where('id', $adGroupId)
            ->firstOrFail();

        return response()->json($adGroup);
    }

    /**
     * Обновить группу объявлений
     */
    public function update(int $campaignId, int $adGroupId, UpdateAdGroupRequest $request): JsonResponse
    {
        $adGroup = $this->adGroupService->updateAdGroup($adGroupId, $request->validated());

        return response()->json($adGroup);
    }

    /**
     * Удалить группу объявлений
     */
    public function destroy(int $campaignId, int $adGroupId): JsonResponse
    {
        $this->adGroupService->deleteAdGroup($adGroupId);

        return response()->json(['message' => 'Ad group deleted successfully']);
    }
}
