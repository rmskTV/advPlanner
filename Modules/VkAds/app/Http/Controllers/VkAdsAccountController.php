<?php

namespace Modules\VkAds\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\VkAds\app\Http\Requests\CreateAccountRequest;
use Modules\VkAds\app\Http\Requests\UpdateAccountRequest;
use Modules\VkAds\app\Services\VkAdsAccountService;
use OpenApi\Annotations as OA;

class VkAdsAccountController extends Controller
{
    public function __construct(
        private VkAdsAccountService $accountService
    ) {}

    /**
     * Получить список рекламных кабинетов
     *
     * @OA\Get(
     *     path="/api/vk-ads/accounts",
     *     tags={"VkAds/Accounts"},
     *     summary="Получить список рекламных кабинетов",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\Parameter(
     *         name="account_type",
     *         in="query",
     *         description="Тип кабинета",
     *
     *         @OA\Schema(type="string", enum={"agency", "client"})
     *     ),
     *
     *     @OA\Parameter(
     *         name="account_status",
     *         in="query",
     *         description="Статус кабинета",
     *
     *         @OA\Schema(type="string", enum={"active", "blocked", "deleted"})
     *     ),
     *
     *     @OA\Response(response=200, description="Список кабинетов"),
     *     @OA\Response(response=401, description="Не авторизован")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['account_type', 'account_status']);
        $accounts = $this->accountService->getAccounts($filters);

        return response()->json($accounts);
    }

    /**
     * Создать/подключить рекламный кабинет
     *
     * @OA\Post(
     *     path="/api/vk-ads/accounts",
     *     tags={"VkAds/Accounts"},
     *     summary="Создать рекламный кабинет",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"vk_account_id", "account_name", "account_type"},
     *
     *             @OA\Property(property="vk_account_id", type="integer"),
     *             @OA\Property(property="account_name", type="string"),
     *             @OA\Property(property="account_type", type="string", enum={"agency", "client"}),
     *             @OA\Property(property="organization_id", type="integer", description="Для агентских кабинетов"),
     *             @OA\Property(property="contract_id", type="integer", description="Для клиентских кабинетов")
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Кабинет создан"),
     *     @OA\Response(response=422, description="Ошибка валидации")
     * )
     */
    public function store(CreateAccountRequest $request): JsonResponse
    {
        $account = $this->accountService->createAccount($request->validated());

        return response()->json($account, 201);
    }

    /**
     * Получить информацию о кабинете
     *
     * @OA\Get(
     *     path="/api/vk-ads/accounts/{id}",
     *     tags={"VkAds/Accounts"},
     *     summary="Получить кабинет",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(response=200, description="Информация о кабинете"),
     *     @OA\Response(response=404, description="Кабинет не найден")
     * )
     */
    public function show(int $id): JsonResponse
    {
        $account = $this->accountService->getAccountsWithAccounting()
            ->where('id', $id)
            ->firstOrFail();

        return response()->json($account);
    }

    /**
     * Обновить кабинет
     */
    public function update(int $id, UpdateAccountRequest $request): JsonResponse
    {
        $account = $this->accountService->updateAccount($id, $request->validated());

        return response()->json($account);
    }

    /**
     * Удалить кабинет
     */
    public function destroy(int $id): JsonResponse
    {
        $this->accountService->deleteAccount($id);

        return response()->json(['message' => 'Account deleted successfully']);
    }

    /**
     * Синхронизировать кабинет с VK Ads
     *
     * @OA\Post(
     *     path="/api/vk-ads/accounts/{id}/sync",
     *     tags={"VkAds/Accounts"},
     *     summary="Синхронизировать кабинет",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\Response(response=200, description="Синхронизация выполнена")
     * )
     */
    public function sync(int $id): JsonResponse
    {
        $account = $this->accountService->getAccounts()->where('id', $id)->firstOrFail();
        $syncedAccount = $this->accountService->syncAccountFromVk($account->vk_account_id);

        return response()->json($syncedAccount);
    }

    /**
     * Синхронизировать все кабинеты
     */
    public function syncAll(): JsonResponse
    {
        $accounts = $this->accountService->syncAllAccounts();

        return response()->json([
            'message' => 'All accounts synced successfully',
            'synced_count' => $accounts->count(),
        ]);
    }

    /**
     * Получить баланс кабинета
     */
    public function getBalance(int $id): JsonResponse
    {
        $account = $this->accountService->getAccounts()->where('id', $id)->firstOrFail();
        $balance = $this->accountService->getAccountBalance($account);

        return response()->json($balance);
    }
}
