<?php

namespace Modules\VkAds\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\VkAds\app\DTOs\StatisticsRequestDTO;
use Modules\VkAds\app\Services\VkAdsStatisticsService;
use OpenApi\Annotations as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VkAdsStatisticsController extends Controller
{
    public function __construct(
        private VkAdsStatisticsService $statisticsService
    ) {}

    /**
     * Получить статистику кампании
     *
     * @OA\Get(
     *     path="/api/vk-ads/campaigns/{campaignId}/statistics",
     *     tags={"VkAds/Statistics"},
     *     summary="Статистика кампании",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\Parameter(
     *         name="date_from",
     *         in="query",
     *         required=true,
     *
     *         @OA\Schema(type="string", format="date")
     *     ),
     *
     *     @OA\Parameter(
     *         name="date_to",
     *         in="query",
     *         required=true,
     *
     *         @OA\Schema(type="string", format="date")
     *     ),
     *
     *     @OA\Response(response=200, description="Статистика кампании")
     * )
     */
    public function getCampaignStats(int $campaignId, Request $request): JsonResponse
    {
        $dto = StatisticsRequestDTO::fromRequest($request);
        $statistics = $this->statisticsService->getCampaignStatistics($campaignId, $dto);

        return response()->json($statistics);
    }

    /**
     * Получить статистику группы объявлений
     */
    public function getAdGroupStats(int $adGroupId, Request $request): JsonResponse
    {
        $dto = StatisticsRequestDTO::fromRequest($request);
        $statistics = $this->statisticsService->getAdGroupStatistics($adGroupId, $dto);

        return response()->json($statistics);
    }

    /**
     * Получить статистику аккаунта
     */
    public function getAccountStats(int $accountId, Request $request): JsonResponse
    {
        $dto = StatisticsRequestDTO::fromRequest($request);
        $statistics = $this->statisticsService->getAccountStatistics($accountId, $dto);

        return response()->json($statistics);
    }

    /**
     * Экспортировать статистику
     *
     * @OA\Post(
     *     path="/api/vk-ads/statistics/export",
     *     tags={"VkAds/Statistics"},
     *     summary="Экспорт статистики",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\Response(response=200, description="Файл со статистикой")
     * )
     */
    public function exportStats(Request $request): StreamedResponse
    {
        $dto = StatisticsRequestDTO::fromRequest($request);
        $format = $request->input('format', 'csv');

        return $this->statisticsService->exportStatistics($dto, $format);
    }
}
