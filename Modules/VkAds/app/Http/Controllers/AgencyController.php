<?php

namespace Modules\VkAds\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\app\Models\Contract;
use Modules\VkAds\app\DTOs\GenerateActDTO;
use Modules\VkAds\app\Http\Requests\CreateClientRequest;
use Modules\VkAds\app\Http\Requests\GenerateActRequest;
use Modules\VkAds\app\Services\AgencyDocumentService;
use Modules\VkAds\app\Services\VkAdsAccountService;
use Modules\VkAds\app\Services\VkAdsAdGroupService;
use OpenApi\Annotations as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgencyController extends Controller
{
    public function __construct(
        private AgencyDocumentService $documentService,
        private VkAdsAccountService $accountService,
        private VkAdsAdGroupService $adGroupService
    ) {}

    /**
     * Получить клиентов агентства
     *
     * @OA\Get(
     *     path="/api/vk-ads/agency/clients",
     *     tags={"VkAds/Agency"},
     *     summary="Получить клиентов агентства",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\Response(response=200, description="Список клиентов агентства")
     * )
     */
    public function getClients(): JsonResponse
    {
        $clients = $this->accountService->getAccounts(['account_type' => 'client']);

        return response()->json($clients);
    }

    /**
     * Создать клиентский кабинет
     *
     * @OA\Post(
     *     path="/api/vk-ads/agency/clients",
     *     tags={"VkAds/Agency"},
     *     summary="Создать клиентский кабинет",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"contract_id", "vk_account_id", "account_name"},
     *
     *             @OA\Property(property="contract_id", type="integer"),
     *             @OA\Property(property="vk_account_id", type="integer"),
     *             @OA\Property(property="account_name", type="string")
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Клиентский кабинет создан")
     * )
     */
    public function createClient(CreateClientRequest $request): JsonResponse
    {
        $contract = Contract::findOrFail($request->input('contract_id'));

        $client = $this->accountService->createClientAccount($contract, [
            'account_id' => $request->input('vk_account_id'),
            'account_name' => $request->input('account_name'),
        ]);

        return response()->json($client, 201);
    }

    /**
     * Обновить клиентский кабинет
     */
    public function updateClient(int $clientId, Request $request): JsonResponse
    {
        $client = $this->accountService->updateAccount($clientId, $request->validated());

        return response()->json($client);
    }

    /**
     * Удалить клиентский кабинет
     */
    public function deleteClient(int $clientId): JsonResponse
    {
        $this->accountService->deleteAccount($clientId);

        return response()->json(['message' => 'Client account deleted successfully']);
    }

    /**
     * Создать группы объявлений из заказа клиента
     *
     * @OA\Post(
     *     path="/api/vk-ads/agency/create-ad-groups-from-order",
     *     tags={"VkAds/Agency"},
     *     summary="Создать группы объявлений из заказа",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"customer_order_id", "campaign_id"},
     *
     *             @OA\Property(property="customer_order_id", type="integer"),
     *             @OA\Property(property="campaign_id", type="integer"),
     *             @OA\Property(property="ad_groups_config", type="array")
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Группы объявлений созданы")
     * )
     */
    public function createAdGroupsFromOrder(Request $request): JsonResponse
    {
        $customerOrderId = $request->input('customer_order_id');
        $campaignId = $request->input('campaign_id');
        $adGroupsConfig = $request->input('ad_groups_config', []);

        $order = \Modules\Accounting\app\Models\CustomerOrder::with('items')
            ->findOrFail($customerOrderId);

        $campaign = \Modules\VkAds\app\Models\VkAdsCampaign::findOrFail($campaignId);

        $createdAdGroups = [];

        foreach ($order->items as $item) {
            if ($this->isAdvertisingService($item->product_name)) {
                $config = $adGroupsConfig[$item->id] ?? [];

                $adGroup = $this->adGroupService->createAdGroupFromOrderItem(
                    $campaign,
                    $item,
                    $config
                );

                $createdAdGroups[] = $adGroup;
            }
        }

        return response()->json([
            'message' => 'Ad groups created successfully',
            'ad_groups' => $createdAdGroups,
        ], 201);
    }

    /**
     * Сгенерировать акт по статистике VK Ads
     *
     * @OA\Post(
     *     path="/api/vk-ads/agency/generate-act",
     *     tags={"VkAds/Agency"},
     *     summary="Сгенерировать акт выполненных работ",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"contract_id", "period_start", "period_end"},
     *
     *             @OA\Property(property="contract_id", type="integer"),
     *             @OA\Property(property="period_start", type="string", format="date"),
     *             @OA\Property(property="period_end", type="string", format="date"),
     *             @OA\Property(property="act_number", type="string")
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Акт сгенерирован")
     * )
     */
    public function generateAct(GenerateActRequest $request): JsonResponse
    {
        $dto = GenerateActDTO::fromRequest($request);
        $contract = Contract::findOrFail($dto->contractId);

        $sale = $this->documentService->generateActFromVkStats(
            $contract,
            $dto->periodStart,
            $dto->periodEnd
        );

        return response()->json([
            'message' => 'Act generated successfully',
            'sale' => $sale->load('items.vkAdsStatistics'),
        ], 201);
    }

    /**
     * Получить документы клиента
     *
     * @OA\Get(
     *     path="/api/vk-ads/agency/clients/{clientId}/documents",
     *     tags={"VkAds/Agency"},
     *     summary="Получить документы клиента",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\Parameter(
     *         name="type",
     *         in="query",
     *
     *         @OA\Schema(type="string", enum={"act", "invoice", "report"})
     *     ),
     *
     *     @OA\Response(response=200, description="Список документов")
     * )
     */
    public function getDocuments(int $clientId, Request $request): JsonResponse
    {
        $type = $request->input('type');
        $documents = $this->documentService->getClientDocuments($clientId, $type);

        return response()->json($documents);
    }

    /**
     * Скачать документ
     *
     * @OA\Get(
     *     path="/api/vk-ads/agency/documents/{documentId}/download",
     *     tags={"VkAds/Agency"},
     *     summary="Скачать документ",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\Response(response=200, description="Файл документа")
     * )
     */
    public function downloadDocument(int $documentId): StreamedResponse
    {
        return $this->documentService->downloadDocument($documentId);
    }

    /**
     * Отправить документ по email
     */
    public function sendDocument(int $documentId, Request $request): JsonResponse
    {
        $email = $request->input('email');
        $sent = $this->documentService->sendDocumentByEmail($documentId, $email);

        return response()->json([
            'message' => $sent ? 'Document sent successfully' : 'Failed to send document',
            'sent' => $sent,
        ]);
    }

    /**
     * Получить отчет по клиенту
     */
    public function getClientReport(int $clientId, Request $request): JsonResponse
    {
        $periodStart = \Carbon\Carbon::parse($request->input('period_start'));
        $periodEnd = \Carbon\Carbon::parse($request->input('period_end'));

        $report = $this->documentService->generateClientReport($clientId, $periodStart, $periodEnd);

        return response()->json($report);
    }

    /**
     * Получить общий отчет агентства
     */
    public function getAgencyReport(Request $request): JsonResponse
    {
        $periodStart = \Carbon\Carbon::parse($request->input('period_start'));
        $periodEnd = \Carbon\Carbon::parse($request->input('period_end'));

        // Получаем ID агентского аккаунта (предполагаем, что он один)
        $agencyAccount = $this->accountService->getAccounts(['account_type' => 'agency'])->first();

        if (! $agencyAccount) {
            return response()->json(['error' => 'Agency account not found'], 404);
        }

        $report = $this->documentService->generateAgencyReport($agencyAccount->id, $periodStart, $periodEnd);

        return response()->json($report);
    }

    /**
     * Получить биллинг клиента
     */
    public function getClientBilling(int $clientId, Request $request): JsonResponse
    {
        $periodStart = \Carbon\Carbon::parse($request->input('period_start'));
        $periodEnd = \Carbon\Carbon::parse($request->input('period_end'));

        $billing = $this->documentService->getClientBilling($clientId, $periodStart, $periodEnd);

        return response()->json($billing);
    }

    /**
     * Получить дашборд агентства с интеграцией учета
     *
     * @OA\Get(
     *     path="/api/vk-ads/agency/dashboard",
     *     tags={"VkAds/Agency"},
     *     summary="Дашборд агентства",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\Response(response=200, description="Данные дашборда")
     * )
     */
    public function getDashboard(): JsonResponse
    {
        return response()->json([
            'agency_accounts' => $this->accountService->getAccounts(['account_type' => 'agency']),
            'client_accounts' => $this->accountService->getAccounts(['account_type' => 'client']),
            'active_campaigns' => \Modules\VkAds\app\Models\VkAdsCampaign::with('adGroups.orderItem.customerOrder')
                ->where('status', 'active')
                ->get(),
            'pending_acts' => $this->getPendingActs(),
            'monthly_stats' => $this->getMonthlyStats(),
        ]);
    }

    /**
     * Проверить, является ли услуга рекламной
     */
    private function isAdvertisingService(string $productName): bool
    {
        $advertisingKeywords = [
            'реклама', 'рекламный', 'преролл', 'аудиоролик',
            'баннер', 'таргет', 'продвижение', 'vk ads',
        ];

        $productNameLower = mb_strtolower($productName);

        foreach ($advertisingKeywords as $keyword) {
            if (str_contains($productNameLower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Получить ожидающие акты
     */
    private function getPendingActs(): array
    {
        // Логика получения ожидающих актов
        // Например, договоры, по которым есть статистика, но нет актов за текущий месяц
        return [];
    }

    /**
     * Получить статистику за месяц
     */
    private function getMonthlyStats(): array
    {
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();

        return [
            'total_spend' => \Modules\VkAds\app\Models\VkAdsStatistics::whereBetween('stats_date', [$startOfMonth, $endOfMonth])
                ->sum('spend'),
            'total_impressions' => \Modules\VkAds\app\Models\VkAdsStatistics::whereBetween('stats_date', [$startOfMonth, $endOfMonth])
                ->sum('impressions'),
            'total_clicks' => \Modules\VkAds\app\Models\VkAdsStatistics::whereBetween('stats_date', [$startOfMonth, $endOfMonth])
                ->sum('clicks'),
            'active_campaigns_count' => \Modules\VkAds\app\Models\VkAdsCampaign::where('status', 'active')->count(),
            'active_clients_count' => $this->accountService->getAccounts(['account_type' => 'client', 'account_status' => 'active'])->count(),
        ];
    }
}
