<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BitrixAppController extends Controller
{
    // ===================== КОНФИГУРАЦИЯ =====================

    /**
     * EntityTypeId смарт-процесса "Договоры"
     */
    protected const ENTITY_TYPE_ID = 1064;

    /**
     * Имя поля для хранения ID реквизита в договоре
     */
    protected const REQUISITE_FIELD = 'ufCrm19RequisiteId';

    /**
     * Ключ кэша для хранения токенов
     */
    protected const CACHE_KEY = 'bitrix_auth';

    /**
     * Время жизни кэша токенов (дни)
     */
    protected const CACHE_TTL_DAYS = 30;

    /**
     * Запас времени до истечения токена для обновления (секунды)
     */
    protected const TOKEN_REFRESH_MARGIN = 300;

    // ===================== РАБОТА С ТОКЕНАМИ =====================

    /**
     * Получить авторизационные данные из запроса Б24
     * Б24 передаёт свежий токен в каждом запросе к placement
     */
    protected function getAuthFromRequest(Request $request): ?array
    {
        $authId = $request->input('AUTH_ID');
        $domain = $request->input('DOMAIN');

        if (!$authId || !$domain) {
            return null;
        }

        return [
            'access_token' => $authId,
            'refresh_token' => $request->input('REFRESH_ID'),
            'domain' => $domain,
            'member_id' => $request->input('member_id'),
            'expires_in' => (int) ($request->input('AUTH_EXPIRES') ?? 3600),
            'saved_at' => time(),
        ];
    }

    /**
     * Получить авторизационные данные из кэша
     */
    protected function getAuthFromCache(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    /**
     * Сохранить авторизационные данные в кэш
     */
    protected function saveAuthToCache(array $auth): void
    {
        Cache::put(self::CACHE_KEY, $auth, now()->addDays(self::CACHE_TTL_DAYS));
    }

    /**
     * Проверить, истёк ли токен
     */
    protected function isTokenExpired(array $auth): bool
    {
        $savedAt = $auth['saved_at'] ?? 0;
        $expiresIn = $auth['expires_in'] ?? 3600;

        return (time() - $savedAt) > ($expiresIn - self::TOKEN_REFRESH_MARGIN);
    }

    /**
     * Обновить токен через refresh_token
     */
    protected function refreshToken(array $auth): ?array
    {
        if (empty($auth['refresh_token'])) {
            Log::warning('Bitrix: Cannot refresh token - no refresh_token');
            return null;
        }

        $clientId = config('services.bitrix.client_id');
        $clientSecret = config('services.bitrix.client_secret');

        if (!$clientId || !$clientSecret) {
            Log::warning('Bitrix: Cannot refresh token - no client credentials in config');
            return null;
        }

        try {
            $response = Http::get('https://oauth.bitrix.info/oauth/token/', [
                'grant_type' => 'refresh_token',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $auth['refresh_token'],
            ])->json();

            if (empty($response['access_token'])) {
                Log::error('Bitrix: Token refresh failed', $response);
                return null;
            }

            $newAuth = [
                'access_token' => $response['access_token'],
                'refresh_token' => $response['refresh_token'],
                'domain' => $auth['domain'],
                'member_id' => $response['member_id'] ?? $auth['member_id'],
                'expires_in' => $response['expires_in'] ?? 3600,
                'saved_at' => time(),
            ];

            $this->saveAuthToCache($newAuth);
            Log::info('Bitrix: Token refreshed successfully');

            return $newAuth;

        } catch (\Exception $e) {
            Log::error('Bitrix: Token refresh exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Получить валидные авторизационные данные
     * Приоритет: запрос -> кэш (с обновлением если истёк)
     */
    protected function getValidAuth(Request $request): ?array
    {
        // 1. Пробуем получить из запроса (всегда свежий)
        $auth = $this->getAuthFromRequest($request);
        if ($auth) {
            // Обновляем кэш свежими данными
            $this->saveAuthToCache($auth);
            return $auth;
        }

        // 2. Берём из кэша
        $auth = $this->getAuthFromCache();
        if (!$auth) {
            return null;
        }

        // 3. Проверяем срок действия и обновляем если нужно
        if ($this->isTokenExpired($auth)) {
            $refreshedAuth = $this->refreshToken($auth);
            if ($refreshedAuth) {
                return $refreshedAuth;
            }
            // Если не удалось обновить - пробуем со старым (может ещё работает)
        }

        return $auth;
    }

    // ===================== API ВЫЗОВЫ =====================

    /**
     * Выполнить REST API запрос к Битрикс24
     */
    protected function callApi(string $method, array $params, array $auth): ?array
    {
        $domain = $auth['domain'];
        $token = $auth['access_token'];

        try {
            $response = Http::get("https://{$domain}/rest/{$method}", array_merge(
                ['auth' => $token],
                $params
            ))->json();

            // Проверяем на ошибку авторизации
            if (isset($response['error']) && $response['error'] === 'expired_token') {
                Log::warning('Bitrix API: Token expired during request');
                return null;
            }

            return $response;

        } catch (\Exception $e) {
            Log::error('Bitrix API error', [
                'method' => $method,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Выполнить POST запрос к REST API
     */
    protected function callApiPost(string $method, array $params, array $auth): ?array
    {
        $domain = $auth['domain'];
        $token = $auth['access_token'];

        try {
            $response = Http::post("https://{$domain}/rest/{$method}", array_merge(
                ['auth' => $token],
                $params
            ))->json();

            return $response;

        } catch (\Exception $e) {
            Log::error('Bitrix API POST error', [
                'method' => $method,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    // ===================== ХЕЛПЕРЫ =====================

    /**
     * Извлечь PLACEMENT_OPTIONS из запроса
     */
    protected function getPlacementOptions(Request $request): array
    {
        if (!$request->has('PLACEMENT_OPTIONS')) {
            return [];
        }

        $raw = $request->input('PLACEMENT_OPTIONS');

        if (is_array($raw)) {
            return $raw;
        }

        return json_decode($raw, true) ?? [];
    }

    /**
     * Получить договор по ID
     */
    protected function getContract(int $contractId, array $auth): ?array
    {
        $response = $this->callApi('crm.item.get', [
            'entityTypeId' => self::ENTITY_TYPE_ID,
            'id' => $contractId
        ], $auth);

        return $response['result']['item'] ?? null;
    }

    /**
     * Получить реквизиты компании
     */
    protected function getCompanyRequisites(int $companyId, array $auth): array
    {
        $response = $this->callApi('crm.requisite.list', [
            'filter' => [
                'ENTITY_TYPE_ID' => 4, // 4 = Компания
                'ENTITY_ID' => $companyId
            ],
            'select' => ['ID', 'NAME', 'RQ_INN', 'RQ_COMPANY_FULL_NAME', 'RQ_KPP']
        ], $auth);

        return $response['result'] ?? [];
    }

    /**
     * Сформировать response с правильными заголовками для iframe Б24
     */
    protected function frameResponse(string $html): Response
    {
        return response($html)
            ->header('Content-Security-Policy', "frame-ancestors 'self' https://*.bitrix24.ru");
    }

    /**
     * Отрендерить view с ошибкой
     */
    protected function errorView(string $message, ?string $domain = null): Response
    {
        $html = view('bitrix.requisite-tab', [
            'entityId' => null,
            'companyId' => null,
            'requisites' => [],
            'currentRequisiteId' => null,
            'contract' => null,
            'domain' => $domain,
            'error' => $message
        ])->render();

        return $this->frameResponse($html);
    }

    // ===================== ОБРАБОТЧИКИ РОУТОВ =====================

    /**
     * Установка приложения
     */
    public function install(Request $request)
    {
        Log::info('Bitrix App: Install started', $request->all());

        $auth = $this->getAuthFromRequest($request);

        if (!$auth) {
            Log::error('Bitrix App: Install failed - no auth data');
            return view('bitrix.install', ['success' => false, 'error' => 'Нет данных авторизации']);
        }

        $this->saveAuthToCache($auth);

        // Регистрируем placement при установке
        $this->registerPlacements($auth);

        return view('bitrix.install', ['success' => true]);
    }

    /**
     * Регистрация placements
     */
    protected function registerPlacements(array $auth): void
    {
        $result = $this->callApiPost('placement.bind', [
            'PLACEMENT' => 'CRM_DYNAMIC_' . self::ENTITY_TYPE_ID . '_DETAIL_TAB',
            'HANDLER' => secure_url('/bitrix/requisite-tab'),
            'TITLE' => 'Реквизит',
        ], $auth);

        Log::info('Bitrix: Placement registered', $result ?? ['error' => 'null response']);
    }

    /**
     * Основной обработчик приложения (меню)
     */
    public function handler(Request $request)
    {
        return view('bitrix.handler');
    }

    /**
     * Вкладка "Реквизит" в карточке договора
     */
    public function requisiteTab(Request $request)
    {
        Log::info('Bitrix: Requisite tab opened', $request->all());

        // Получаем авторизацию
        $auth = $this->getValidAuth($request);
        if (!$auth) {
            return $this->errorView('Нет авторизации. Переустановите приложение.');
        }

        // Получаем ID договора из PLACEMENT_OPTIONS
        $placementOptions = $this->getPlacementOptions($request);
        $entityId = isset($placementOptions['ID']) ? (int) $placementOptions['ID'] : null;

        Log::info('Bitrix: Entity ID', ['id' => $entityId, 'options' => $placementOptions]);

        if (!$entityId) {
            return $this->errorView('Не удалось определить договор.', $auth['domain']);
        }

        // Получаем договор
        $contract = $this->getContract($entityId, $auth);

        if (!$contract) {
            return $this->errorView('Не удалось загрузить данные договора.', $auth['domain']);
        }

        Log::info('Bitrix: Contract loaded', ['id' => $entityId, 'companyId' => $contract['companyId'] ?? null]);

        $companyId = $contract['companyId'] ?? null;
        $currentRequisiteId = $contract[self::REQUISITE_FIELD] ?? null;

        if (!$companyId) {
            return $this->errorView('К договору не привязан клиент. Сначала укажите компанию в договоре.', $auth['domain']);
        }

        // Получаем реквизиты компании
        $requisites = $this->getCompanyRequisites($companyId, $auth);

        Log::info('Bitrix: Requisites loaded', ['count' => count($requisites)]);

        $html = view('bitrix.requisite-tab', [
            'entityId' => $entityId,
            'companyId' => $companyId,
            'requisites' => $requisites,
            'currentRequisiteId' => $currentRequisiteId,
            'contract' => $contract,
            'domain' => $auth['domain'],
            'error' => null,
            'requisiteField' => self::REQUISITE_FIELD, // Передаём имя поля во view
            'entityTypeId' => self::ENTITY_TYPE_ID,
        ])->render();

        return $this->frameResponse($html);
    }

    /**
     * API: Получить реквизиты компании (AJAX)
     */
    public function getRequisites(Request $request, int $companyId): JsonResponse
    {
        $auth = $this->getValidAuth($request);

        if (!$auth) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $requisites = $this->getCompanyRequisites($companyId, $auth);

        return response()->json($requisites);
    }

    /**
     * API: Сохранить выбранный реквизит (AJAX)
     */
    public function saveRequisite(Request $request): JsonResponse
    {
        $auth = $this->getValidAuth($request);

        if (!$auth) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $entityId = (int) $request->input('entity_id');
        $requisiteId = (int) $request->input('requisite_id');

        if (!$entityId) {
            return response()->json(['error' => 'Invalid entity_id'], 400);
        }

        $result = $this->callApiPost('crm.item.update', [
            'entityTypeId' => self::ENTITY_TYPE_ID,
            'id' => $entityId,
            'fields' => [
                self::REQUISITE_FIELD => $requisiteId
            ]
        ], $auth);

        if (isset($result['error'])) {
            Log::error('Bitrix: Save requisite failed', $result);
            return response()->json(['error' => $result['error_description'] ?? 'Unknown error'], 500);
        }

        Log::info('Bitrix: Requisite saved', ['entityId' => $entityId, 'requisiteId' => $requisiteId]);

        return response()->json(['success' => true, 'result' => $result]);
    }

    // ===================== DEBUG / СЛУЖЕБНЫЕ =====================

    /**
     * Отладочная информация
     */
    public function debug(Request $request): JsonResponse
    {
        $auth = $this->getValidAuth($request);

        if (!$auth) {
            return response()->json(['error' => 'No auth data']);
        }

        $types = $this->callApi('crm.type.list', [], $auth);
        $placements = $this->callApi('placement.get', [], $auth);

        return response()->json([
            'smart_processes' => $types,
            'placements' => $placements,
            'auth_info' => [
                'domain' => $auth['domain'],
                'token_saved_at' => $auth['saved_at'] ?? null,
                'expires_in' => $auth['expires_in'] ?? null,
                'is_expired' => $this->isTokenExpired($auth),
            ],
            'config' => [
                'entity_type_id' => self::ENTITY_TYPE_ID,
                'requisite_field' => self::REQUISITE_FIELD,
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Перерегистрация placement (исправление HTTP→HTTPS)
     */
    public function fixPlacement(Request $request): JsonResponse
    {
        $auth = $this->getValidAuth($request);

        if (!$auth) {
            return response()->json(['error' => 'No auth data']);
        }

        $placement = 'CRM_DYNAMIC_' . self::ENTITY_TYPE_ID . '_DETAIL_TAB';
        $handler = secure_url('/bitrix/requisite-tab');

        // Удаляем старые (http и https на всякий случай)
        $unbindHttp = $this->callApiPost('placement.unbind', [
            'PLACEMENT' => $placement,
            'HANDLER' => str_replace('https://', 'http://', $handler),
        ], $auth);

        $unbindHttps = $this->callApiPost('placement.unbind', [
            'PLACEMENT' => $placement,
            'HANDLER' => $handler,
        ], $auth);

        // Регистрируем заново
        $bind = $this->callApiPost('placement.bind', [
            'PLACEMENT' => $placement,
            'HANDLER' => $handler,
            'TITLE' => 'Реквизит',
        ], $auth);

        return response()->json([
            'unbind_http' => $unbindHttp,
            'unbind_https' => $unbindHttps,
            'bind' => $bind,
            'handler_url' => $handler,
        ], JSON_PRETTY_PRINT);
    }
}
