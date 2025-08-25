<?php

namespace Modules\VkAds\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\VkAds\app\DTOs\BudgetUpdateDTO;
use Modules\VkAds\app\DTOs\CampaignFiltersDTO;
use Modules\VkAds\app\DTOs\CreateCampaignDTO;
use Modules\VkAds\app\DTOs\UpdateCampaignDTO;
use Modules\VkAds\app\Http\Requests\CreateCampaignRequest;
use Modules\VkAds\app\Http\Requests\UpdateCampaignRequest;
use Modules\VkAds\app\Services\VkAdsAccountService;
use Modules\VkAds\app\Services\VkAdsCampaignService;
use OpenApi\Annotations as OA;

class VkAdsCampaignController extends Controller
{
    public function __construct(
        private VkAdsCampaignService $campaignService,
        private VkAdsAccountService $accountService
    ) {}

    /**
     * Получить список кампаний для аккаунта
     *
     * @OA\Get(
     *     path="/api/vk-ads/accounts/{accountId}/campaigns",
     *     tags={"VkAds/Campaigns"},
     *     summary="Получить кампании аккаунта",
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
     *         name="status",
     *         in="query",
     *
     *         @OA\Schema(type="string", enum={"active", "paused", "deleted", "archived"})
     *     ),
     *
     *     @OA\Response(response=200, description="Список кампаний")
     * )
     */
    public function index(int $accountId, Request $request): JsonResponse
    {
        $account = $this->accountService->getAccounts()->where('id', $accountId)->firstOrFail();

        $filters = CampaignFiltersDTO::fromRequest($request);
        $campaigns = $this->campaignService->getCampaigns($account, $filters);

        return response()->json($campaigns);
    }

    /**
     * Создать кампанию
     *
     * @OA\Post(
     *     path="/api/vk-ads/accounts/{accountId}/campaigns",
     *     tags={"VkAds/Campaigns"},
     *     summary="Создать кампанию",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"name", "campaign_type", "start_date"},
     *
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="campaign_type", type="string"),
     *             @OA\Property(property="daily_budget", type="number"),
     *             @OA\Property(property="start_date", type="string", format="date")
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Кампания создана")
     * )
     */
    public function store(int $accountId, CreateCampaignRequest $request): JsonResponse
    {
        $account = $this->accountService->getAccounts()->where('id', $accountId)->firstOrFail();

        $dto = CreateCampaignDTO::fromRequest($request);
        $campaign = $this->campaignService->createCampaign($account, $dto);

        return response()->json($campaign, 201);
    }

    /**
     * Получить кампанию
     */
    public function show(int $accountId, int $campaignId): JsonResponse
    {
        $campaign = $this->campaignService->getCampaign($campaignId);

        return response()->json($campaign);
    }

    /**
     * Обновить кампанию
     */
    public function update(int $accountId, int $campaignId, UpdateCampaignRequest $request): JsonResponse
    {
        $dto = UpdateCampaignDTO::fromRequest($request);
        $campaign = $this->campaignService->updateCampaign($campaignId, $dto);

        return response()->json($campaign);
    }

    /**
     * Удалить кампанию
     */
    public function destroy(int $accountId, int $campaignId): JsonResponse
    {
        $this->campaignService->deleteCampaign($campaignId);

        return response()->json(['message' => 'Campaign deleted successfully']);
    }

    /**
     * Поставить кампанию на паузу
     *
     * @OA\Post(
     *     path="/api/vk-ads/campaigns/{campaignId}/pause",
     *     tags={"VkAds/Campaigns"},
     *     summary="Поставить кампанию на паузу",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\Response(response=200, description="Кампания поставлена на паузу")
     * )
     */
    public function pause(int $accountId, int $campaignId): JsonResponse
    {
        $campaign = $this->campaignService->pauseCampaign($campaignId);

        return response()->json($campaign);
    }

    /**
     * Возобновить кампанию
     */
    public function resume(int $accountId, int $campaignId): JsonResponse
    {
        $campaign = $this->campaignService->resumeCampaign($campaignId);

        return response()->json($campaign);
    }

    /**
     * Скопировать кампанию
     */
    public function copy(int $accountId, int $campaignId, Request $request): JsonResponse
    {
        $modifications = $request->input('modifications', []);
        $campaign = $this->campaignService->copyCampaign($campaignId, $modifications);

        return response()->json($campaign, 201);
    }

    /**
     * Обновить бюджет кампании
     */
    public function updateBudget(int $accountId, int $campaignId, Request $request): JsonResponse
    {
        $dto = BudgetUpdateDTO::fromRequest($request);
        $campaign = $this->campaignService->updateBudget($campaignId, $dto);

        return response()->json($campaign);
    }
}
