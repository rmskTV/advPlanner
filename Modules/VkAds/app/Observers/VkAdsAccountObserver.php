<?php

namespace Modules\VkAds\app\Observers;

use Modules\VkAds\app\Models\VkAdsAccount;
use Modules\VkAds\app\Events\AccountConnected;
use Modules\VkAds\app\Events\AccountDisconnected;
use Modules\VkAds\app\Jobs\SyncAccountData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VkAdsAccountObserver
{
    /**
     * Обработка после создания аккаунта
     */
    public function created(VkAdsAccount $account): void
    {
        Log::info('VK Ads Account created', [
            'account_id' => $account->id,
            'vk_account_id' => $account->vk_account_id,
            'account_name' => $account->account_name,
            'account_type' => $account->account_type,
            'organization_id' => $account->organization_id,
            'contract_id' => $account->contract_id
        ]);

        // Сбрасываем кэш списка аккаунтов
        Cache::tags(['vk-ads-accounts'])->flush();

        // Генерируем событие подключения аккаунта
        if (class_exists(AccountConnected::class)) {
            AccountConnected::dispatch($account);
        }

        // Запланируем первичную синхронизацию через 5 минут
        if ($account->sync_enabled) {
            SyncAccountData::dispatch($account)->delay(now()->addMinutes(5));
        }

        // Логируем для аудита
        $this->logAccountAction($account, 'created');
    }

    /**
     * Обработка после обновления аккаунта
     */
    public function updated(VkAdsAccount $account): void
    {
        $changes = $account->getChanges();
        $originalValues = $account->getOriginal();

        // Логируем изменение статуса аккаунта
        if ($account->isDirty('account_status')) {
            $this->handleStatusChange($account, $originalValues['account_status'], $account->account_status);
        }

        // Логируем изменение баланса
        if ($account->isDirty('balance')) {
            Log::info('VK Ads Account balance updated', [
                'account_id' => $account->id,
                'old_balance' => $originalValues['balance'],
                'new_balance' => $account->balance,
                'currency' => $account->currency,
                'difference' => $account->balance - $originalValues['balance']
            ]);

            // Проверяем низкий баланс
            $this->checkLowBalance($account);
        }

        // Логируем изменение настроек синхронизации
        if ($account->isDirty('sync_enabled')) {
            Log::info('VK Ads Account sync settings changed', [
                'account_id' => $account->id,
                'sync_enabled' => $account->sync_enabled,
                'old_value' => $originalValues['sync_enabled']
            ]);

            // Если синхронизация включена, запускаем немедленную синхронизацию
            if ($account->sync_enabled && !$originalValues['sync_enabled']) {
                SyncAccountData::dispatch($account);
            }
        }

        // Обрабатываем изменения связей с Accounting
        if ($account->isDirty(['organization_id', 'contract_id'])) {
            $this->updateRelatedData($account);
        }

        // Обрабатываем изменения прав доступа
        if ($account->isDirty(['access_roles', 'can_view_budget'])) {
            $this->handleAccessRolesChange($account);
        }

        // Проверяем целостность данных
        $this->validateAccountIntegrity($account);

        // Обновляем метрики аккаунта
        $this->updateAccountMetrics($account);

        // Сбрасываем кэш аккаунта
        Cache::forget("vk_ads_account_{$account->id}");

        // Логируем для аудита
        if (!empty($changes)) {
            $this->logAccountAction($account, 'updated', $changes);
        }
    }

    /**
     * Обработка перед удалением аккаунта
     */
    public function deleting(VkAdsAccount $account): void
    {
        Log::warning('VK Ads Account being deleted', [
            'account_id' => $account->id,
            'vk_account_id' => $account->vk_account_id,
            'account_name' => $account->account_name,
            'account_type' => $account->account_type,
            'campaigns_count' => $account->campaigns()->count(),
            'active_campaigns_count' => $account->campaigns()->where('status', 'active')->count()
        ]);

        // Проверяем, есть ли активные кампании
        $activeCampaigns = $account->campaigns()->where('status', 'active')->count();

        if ($activeCampaigns > 0) {
            Log::warning("Deleting account with {$activeCampaigns} active campaigns", [
                'account_id' => $account->id
            ]);
        }

        // Деактивируем все токены перед удалением
        $this->deactivateAccountTokens($account);

        // Логируем для аудита
        $this->logAccountAction($account, 'deleting');
    }

    /**
     * Обработка после удаления аккаунта
     */
    public function deleted(VkAdsAccount $account): void
    {
        Log::info('VK Ads Account deleted', [
            'account_id' => $account->id,
            'vk_account_id' => $account->vk_account_id,
            'account_name' => $account->account_name
        ]);

        // Сбрасываем все связанные кэши
        $this->invalidateRelatedCache($account);

        // Генерируем событие отключения аккаунта
        if (class_exists(AccountDisconnected::class)) {
            AccountDisconnected::dispatch($account);
        }

        // Логируем для аудита
        $this->logAccountAction($account, 'deleted');
    }

    /**
     * Обработка восстановления аккаунта (после soft delete)
     */
    public function restored(VkAdsAccount $account): void
    {
        Log::info('VK Ads Account restored', [
            'account_id' => $account->id,
            'account_name' => $account->account_name
        ]);

        // Сбрасываем кэш
        Cache::tags(['vk-ads-accounts'])->flush();

        // Запускаем синхронизацию после восстановления
        if ($account->sync_enabled) {
            SyncAccountData::dispatch($account)->delay(now()->addMinutes(1));
        }

        // Логируем для аудита
        $this->logAccountAction($account, 'restored');
    }

    // === ПРИВАТНЫЕ МЕТОДЫ ===

    /**
     * Деактивация всех токенов аккаунта
     */
    private function deactivateAccountTokens(VkAdsAccount $account): void
    {
        $deactivatedCount = $account->tokens()
            ->where('is_active', true)
            ->update(['is_active' => false]);

        if ($deactivatedCount > 0) {
            Log::info("Deactivated {$deactivatedCount} tokens for account {$account->id}");
        }
    }

    /**
     * Проверка низкого баланса
     */
    private function checkLowBalance(VkAdsAccount $account): void
    {
        $lowBalanceThreshold = config('vkads.notifications.low_balance_threshold', 1000); // 1000 рублей

        if ($account->balance < $lowBalanceThreshold && $account->balance > 0) {
            Log::warning('Low balance detected', [
                'account_id' => $account->id,
                'account_name' => $account->account_name,
                'current_balance' => $account->balance,
                'threshold' => $lowBalanceThreshold,
                'currency' => $account->currency
            ]);

            // Отправляем уведомление о низком балансе
            if (config('vkads.notifications.low_balance_alerts', true)) {
                \Modules\VkAds\app\Jobs\SendLowBalanceAlert::dispatch($account);
            }
        }
    }

    /**
     * Сброс связанного кэша
     */
    private function invalidateRelatedCache(VkAdsAccount $account): void
    {
        // Сбрасываем кэш аккаунта
        Cache::forget("vk_ads_account_{$account->id}");

        // Сбрасываем кэш кампаний аккаунта
        Cache::forget("vk_ads_campaigns_account_{$account->id}");

        // Сбрасываем кэш статистики
        Cache::tags(['vk-ads-statistics'])->flush();

        // Сбрасываем кэш дашборда агентства
        Cache::forget('agency_dashboard');

        // Если это клиентский аккаунт, сбрасываем кэш договора
        if ($account->isClient() && $account->contract_id) {
            Cache::forget("contract_vk_ads_{$account->contract_id}");
        }

        // Если это агентский аккаунт, сбрасываем кэш организации
        if ($account->isAgency() && $account->organization_id) {
            Cache::forget("organization_vk_ads_{$account->organization_id}");
        }
    }

    /**
     * Автоматические действия при изменении статуса
     */
    private function handleStatusChange(VkAdsAccount $account, string $oldStatus, string $newStatus): void
    {
        Log::info('VK Ads Account status changed', [
            'account_id' => $account->id,
            'account_name' => $account->account_name,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'changed_at' => now()
        ]);

        // При активации аккаунта
        if ($oldStatus !== 'active' && $newStatus === 'active') {
            Log::info("Account activated, scheduling sync", [
                'account_id' => $account->id
            ]);

            // Запускаем синхронизацию
            if ($account->sync_enabled) {
                SyncAccountData::dispatch($account);
            }
        }

        // При блокировке аккаунта
        if ($newStatus === 'blocked') {
            Log::warning("Account blocked, pausing all campaigns", [
                'account_id' => $account->id
            ]);

            // Автоматически ставим все кампании на паузу
            $account->campaigns()
                ->where('status', 'active')
                ->update(['status' => 'paused']);
        }

        // При удалении аккаунта
        if ($newStatus === 'deleted') {
            Log::warning("Account marked as deleted", [
                'account_id' => $account->id
            ]);

            // Архивируем все кампании
            $account->campaigns()->update(['status' => 'archived']);
        }
    }

    /**
     * Проверка и обновление связанных данных
     */
    private function updateRelatedData(VkAdsAccount $account): void
    {
        Log::info("VK Ads Account accounting relations changed", [
            'account_id' => $account->id,
            'old_organization_id' => $account->getOriginal('organization_id'),
            'new_organization_id' => $account->organization_id,
            'old_contract_id' => $account->getOriginal('contract_id'),
            'new_contract_id' => $account->contract_id
        ]);

        // Сбрасываем кэш связанных данных
        $this->invalidateRelatedCache($account);

        // Если изменился договор для клиентского аккаунта
        if ($account->isClient() && $account->isDirty('contract_id')) {
            // Обновляем связи групп объявлений с заказами нового договора
            $this->updateAdGroupOrderItems($account);
        }
    }

    /**
     * Обновление связей групп объявлений с новым договором
     */
    private function updateAdGroupOrderItems(VkAdsAccount $account): void
    {
        if (!$account->contract_id) {
            return;
        }

        $adGroups = $account->campaigns()
            ->with('adGroups')
            ->get()
            ->flatMap->adGroups;

        foreach ($adGroups as $adGroup) {
            if (!$adGroup->customer_order_item_id) {
                // Пытаемся найти подходящую строку заказа в новом договоре
                $orderItem = \Modules\Accounting\app\Models\CustomerOrderItem::whereHas('customerOrder', function ($query) use ($account) {
                    $query->whereHas('contract', function ($contractQuery) use ($account) {
                        $contractQuery->where('id', $account->contract_id);
                    });
                })->where('product_name', 'like', "%{$adGroup->name}%")
                    ->first();

                if ($orderItem) {
                    $adGroup->update(['customer_order_item_id' => $orderItem->id]);

                    Log::info("Ad group linked to order item", [
                        'ad_group_id' => $adGroup->id,
                        'order_item_id' => $orderItem->id,
                        'product_name' => $orderItem->product_name
                    ]);
                }
            }
        }
    }

    /**
     * Обработка изменений прав доступа
     */
    private function handleAccessRolesChange(VkAdsAccount $account): void
    {
        if ($account->isDirty('access_roles')) {
            $oldRoles = $account->getOriginal('access_roles') ?? [];
            $newRoles = $account->access_roles ?? [];

            Log::info('VK Ads Account access roles changed', [
                'account_id' => $account->id,
                'old_roles' => $oldRoles,
                'new_roles' => $newRoles,
                'added_roles' => array_diff($newRoles, $oldRoles),
                'removed_roles' => array_diff($oldRoles, $newRoles)
            ]);
        }

        if ($account->isDirty('can_view_budget')) {
            Log::info('VK Ads Account budget access changed', [
                'account_id' => $account->id,
                'can_view_budget' => $account->can_view_budget,
                'old_value' => $account->getOriginal('can_view_budget')
            ]);
        }
    }

    /**
     * Проверка целостности данных при обновлении
     */
    private function validateAccountIntegrity(VkAdsAccount $account): void
    {
        // Проверяем, что агентский аккаунт связан с организацией
        if ($account->isAgency() && !$account->organization_id) {
            Log::error('Agency account without organization', [
                'account_id' => $account->id
            ]);
        }

        // Проверяем, что клиентский аккаунт связан с договором
        if ($account->isClient() && !$account->contract_id) {
            Log::error('Client account without contract', [
                'account_id' => $account->id
            ]);
        }

        // Проверяем уникальность VK account ID
        $duplicates = VkAdsAccount::where('vk_account_id', $account->vk_account_id)
            ->where('id', '!=', $account->id)
            ->count();

        if ($duplicates > 0) {
            Log::error('Duplicate VK account ID detected', [
                'account_id' => $account->id,
                'vk_account_id' => $account->vk_account_id,
                'duplicates_count' => $duplicates
            ]);
        }
    }

    /**
     * Мониторинг производительности аккаунта
     */
    private function monitorAccountPerformance(VkAdsAccount $account): void
    {
        // Запускаем мониторинг только для активных аккаунтов
        if ($account->account_status !== 'active') {
            return;
        }

        // Проверяем последнюю синхронизацию
        $lastSync = $account->last_sync_at;
        $syncThreshold = now()->subHours(config('vkads.monitoring.sync_alert_hours', 6));

        if (!$lastSync || $lastSync->lt($syncThreshold)) {
            Log::warning('Account sync is outdated', [
                'account_id' => $account->id,
                'last_sync_at' => $lastSync?->toDateTimeString(),
                'threshold' => $syncThreshold->toDateTimeString()
            ]);
        }

        // Проверяем количество активных токенов
        $activeTokensCount = $account->tokens()->where('is_active', true)->count();

        if ($activeTokensCount === 0) {
            Log::error('Account has no active tokens', [
                'account_id' => $account->id
            ]);
        } elseif ($activeTokensCount > 1) {
            Log::warning('Account has multiple active tokens', [
                'account_id' => $account->id,
                'active_tokens_count' => $activeTokensCount
            ]);
        }
    }

    /**
     * Обновление метрик аккаунта
     */
    private function updateAccountMetrics(VkAdsAccount $account): void
    {
        try {
            // Кэшируем основные метрики аккаунта
            $metrics = [
                'campaigns_count' => $account->campaigns()->count(),
                'active_campaigns_count' => $account->campaigns()->where('status', 'active')->count(),
                'total_spend_month' => $this->getMonthlySpend($account),
                'last_activity' => $account->campaigns()
                    ->with('adGroups.statistics')
                    ->get()
                    ->flatMap->adGroups
                    ->flatMap->statistics
                    ->max('stats_date'),
                'updated_at' => now()
            ];

            Cache::put("vk_ads_account_metrics_{$account->id}", $metrics, now()->addHours(1));

        } catch (\Exception $e) {
            Log::error('Failed to update account metrics: ' . $e->getMessage(), [
                'account_id' => $account->id
            ]);
        }
    }

    /**
     * Получение трат за текущий месяц
     */
    private function getMonthlySpend(VkAdsAccount $account): float
    {
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();

        return $account->campaigns()
            ->with(['adGroups.statistics' => function ($query) use ($startOfMonth, $endOfMonth) {
                $query->whereBetween('stats_date', [$startOfMonth, $endOfMonth]);
            }])
            ->get()
            ->flatMap->adGroups
            ->flatMap->statistics
            ->sum('spend');
    }

    /**
     * ЕДИНСТВЕННЫЙ метод логирования действий с аккаунтом
     */
    private function logAccountAction(VkAdsAccount $account, string $action, array $changes = []): void
    {
        $logData = [
            'module' => 'VkAds',
            'model' => 'VkAdsAccount',
            'action' => $action,
            'account_id' => $account->id,
            'vk_account_id' => $account->vk_account_id,
            'account_name' => $account->account_name,
            'account_type' => $account->account_type,
            'timestamp' => now()->toISOString(),
            'user_id' => auth()->id(),
            'session_id' => session()->getId(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ];

        // Добавляем изменения, если есть
        if (!empty($changes)) {
            $logData['changes'] = $changes;
        }

        // Добавляем контекстную информацию
        if ($account->isAgency()) {
            $logData['organization_id'] = $account->organization_id;
            $logData['organization_name'] = $account->organization?->name;
        } elseif ($account->isClient()) {
            $logData['contract_id'] = $account->contract_id;
            $logData['contract_number'] = $account->contract?->number;
            $logData['counterparty_name'] = $account->counterparty?->name;
        }

        // Записываем в лог аудита
        Log::channel('audit')->info("VkAdsAccount.{$action}", $logData);

        // Если есть таблица аудита, записываем туда
        if (class_exists('\App\Models\AuditLog')) {
            \App\Models\AuditLog::create([
                'model_type' => VkAdsAccount::class,
                'model_id' => $account->id,
                'action' => $action,
                'changes' => $changes,
                'user_id' => auth()->id(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'created_at' => now()
            ]);
        }
    }
}
