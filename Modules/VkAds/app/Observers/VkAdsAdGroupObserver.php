<?php

namespace Modules\VkAds\app\Observers;

use Modules\VkAds\app\Models\VkAdsAdGroup;
use Modules\VkAds\app\Events\AdGroupCreated;
use Modules\VkAds\app\Events\AdGroupStatusChanged;
use Modules\VkAds\app\Jobs\SyncAdGroupData;
use Modules\VkAds\app\Jobs\ValidateAdGroupTargeting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VkAdsAdGroupObserver
{
    /**
     * Обработка после создания группы объявлений
     */
    public function created(VkAdsAdGroup $adGroup): void
    {
        Log::info('VK Ads Ad Group created', [
            'ad_group_id' => $adGroup->id,
            'vk_ad_group_id' => $adGroup->vk_ad_group_id,
            'name' => $adGroup->name,
            'campaign_id' => $adGroup->vk_ads_campaign_id,
            'customer_order_item_id' => $adGroup->customer_order_item_id,
            'bid' => $adGroup->bid,
            'targeting_count' => count($adGroup->targeting ?? [])
        ]);

        // Сбрасываем кэш кампании
        $this->invalidateCampaignCache($adGroup);

        // Генерируем событие создания группы объявлений
        if (class_exists(AdGroupCreated::class)) {
            AdGroupCreated::dispatch($adGroup);
        }

        // Валидируем таргетинг в фоне
        if (!empty($adGroup->targeting)) {
            ValidateAdGroupTargeting::dispatch($adGroup)->delay(now()->addMinutes(2));
        }

        // Если группа привязана к строке заказа, логируем связь
        if ($adGroup->customer_order_item_id) {
            $this->logOrderItemConnection($adGroup);
        }

        // Логируем для аудита
        $this->logAdGroupAction($adGroup, 'created');
    }

    /**
     * Обработка после обновления группы объявлений
     */
    public function updated(VkAdsAdGroup $adGroup): void
    {
        $changes = $adGroup->getChanges();
        $originalValues = $adGroup->getOriginal();

        // Обрабатываем изменение статуса
        if ($adGroup->isDirty('status')) {
            $this->handleStatusChange($adGroup, $originalValues['status'], $adGroup->status);
        }

        // Обрабатываем изменение ставки
        if ($adGroup->isDirty('bid')) {
            Log::info('Ad Group bid changed', [
                'ad_group_id' => $adGroup->id,
                'old_bid' => $originalValues['bid'],
                'new_bid' => $adGroup->bid,
                'difference' => $adGroup->bid - ($originalValues['bid'] ?? 0),
                'change_percentage' => $originalValues['bid'] > 0
                    ? (($adGroup->bid - $originalValues['bid']) / $originalValues['bid']) * 100
                    : null
            ]);

            // Если ставка значительно изменилась, планируем мониторинг производительности
            if ($this->isSignificantBidChange($originalValues['bid'], $adGroup->bid)) {
                \Modules\VkAds\app\Jobs\MonitorBidPerformance::dispatch($adGroup)
                    ->delay(now()->addHours(2));
            }
        }

        // Обрабатываем изменение таргетинга
        if ($adGroup->isDirty('targeting')) {
            $this->handleTargetingChange($adGroup, $originalValues['targeting'], $adGroup->targeting);
        }

        // Обрабатываем изменение размещений
        if ($adGroup->isDirty('placements')) {
            Log::info('Ad Group placements changed', [
                'ad_group_id' => $adGroup->id,
                'old_placements' => $originalValues['placements'],
                'new_placements' => $adGroup->placements
            ]);
        }

        // Обрабатываем изменение привязки к заказу
        if ($adGroup->isDirty('customer_order_item_id')) {
            $this->handleOrderItemChange($adGroup, $originalValues['customer_order_item_id'], $adGroup->customer_order_item_id);
        }

        // Проверяем целостность данных
        $this->validateAdGroupIntegrity($adGroup);

        // Обновляем метрики группы
        $this->updateAdGroupMetrics($adGroup);

        // Сбрасываем кэш
        $this->invalidateAdGroupCache($adGroup);

        // Логируем для аудита
        if (!empty($changes)) {
            $this->logAdGroupAction($adGroup, 'updated', $changes);
        }
    }

    /**
     * Обработка перед удалением группы объявлений
     */
    public function deleting(VkAdsAdGroup $adGroup): void
    {
        $adsCount = $adGroup->ads()->count();
        $activeAdsCount = $adGroup->ads()->where('status', 'active')->count();

        Log::warning('VK Ads Ad Group being deleted', [
            'ad_group_id' => $adGroup->id,
            'vk_ad_group_id' => $adGroup->vk_ad_group_id,
            'name' => $adGroup->name,
            'campaign_id' => $adGroup->vk_ads_campaign_id,
            'ads_count' => $adsCount,
            'active_ads_count' => $activeAdsCount,
            'total_spend' => $adGroup->getTotalSpend()
        ]);

        // Предупреждаем об удалении группы с активными объявлениями
        if ($activeAdsCount > 0) {
            Log::warning("Deleting ad group with {$activeAdsCount} active ads", [
                'ad_group_id' => $adGroup->id,
                'active_ads' => $adGroup->ads()->where('status', 'active')->pluck('name')->toArray()
            ]);
        }

        // Архивируем все объявления группы
        $adGroup->ads()->update(['status' => 'archived']);

        // Логируем для аудита
        $this->logAdGroupAction($adGroup, 'deleting');
    }

    /**
     * Обработка после удаления группы объявлений
     */
    public function deleted(VkAdsAdGroup $adGroup): void
    {
        Log::info('VK Ads Ad Group deleted', [
            'ad_group_id' => $adGroup->id,
            'vk_ad_group_id' => $adGroup->vk_ad_group_id,
            'name' => $adGroup->name
        ]);

        // Сбрасываем все связанные кэши
        $this->invalidateAdGroupCache($adGroup);
        $this->invalidateCampaignCache($adGroup);

        // Логируем для аудита
        $this->logAdGroupAction($adGroup, 'deleted');
    }

    /**
     * Обработка восстановления группы объявлений
     */
    public function restored(VkAdsAdGroup $adGroup): void
    {
        Log::info('VK Ads Ad Group restored', [
            'ad_group_id' => $adGroup->id,
            'name' => $adGroup->name
        ]);

        // Восстанавливаем объявления группы
        $adGroup->ads()->onlyTrashed()->restore();

        // Сбрасываем кэш
        $this->invalidateAdGroupCache($adGroup);

        // Запускаем синхронизацию
        SyncAdGroupData::dispatch($adGroup)->delay(now()->addMinutes(1));

        // Логируем для аудита
        $this->logAdGroupAction($adGroup, 'restored');
    }

    // === ПРИВАТНЫЕ МЕТОДЫ ===

    /**
     * Обработка изменения статуса группы объявлений
     */
    private function handleStatusChange(VkAdsAdGroup $adGroup, string $oldStatus, string $newStatus): void
    {
        Log::info('Ad Group status changed', [
            'ad_group_id' => $adGroup->id,
            'name' => $adGroup->name,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'changed_at' => now()
        ]);

        // Генерируем событие изменения статуса
        if (class_exists(AdGroupStatusChanged::class)) {
            AdGroupStatusChanged::dispatch($adGroup, $oldStatus, $newStatus);
        }

        // При активации группы объявлений
        if ($oldStatus !== 'active' && $newStatus === 'active') {
            Log::info("Ad group activated, checking setup", [
                'ad_group_id' => $adGroup->id
            ]);

            // Проверяем, есть ли объявления в группе
            $adsCount = $adGroup->ads()->count();
            if ($adsCount === 0) {
                Log::warning("Activated ad group has no ads", [
                    'ad_group_id' => $adGroup->id
                ]);
            }

            // Планируем мониторинг производительности
            \Modules\VkAds\app\Jobs\MonitorAdGroupPerformance::dispatch($adGroup)
                ->delay(now()->addHours(1));
        }

        // При паузе группы объявлений
        if ($newStatus === 'paused') {
            Log::info("Ad group paused", [
                'ad_group_id' => $adGroup->id,
                'reason' => 'manual_or_automatic'
            ]);

            // Автоматически ставим все объявления группы на паузу
            $pausedAds = $adGroup->ads()
                ->where('status', 'active')
                ->update(['status' => 'paused']);

            if ($pausedAds > 0) {
                Log::info("Paused {$pausedAds} ads in ad group {$adGroup->id}");
            }
        }
    }

    /**
     * Обработка изменения таргетинга
     */
    private function handleTargetingChange(VkAdsAdGroup $adGroup, ?array $oldTargeting, ?array $newTargeting): void
    {
        Log::info('Ad Group targeting changed', [
            'ad_group_id' => $adGroup->id,
            'old_targeting' => $oldTargeting,
            'new_targeting' => $newTargeting,
            'targeting_changes' => $this->analyzeTargetingChanges($oldTargeting, $newTargeting)
        ]);

        // Валидируем новый таргетинг
        ValidateAdGroupTargeting::dispatch($adGroup);

        // Если изменения значительные, планируем анализ влияния на производительность
        if ($this->isSignificantTargetingChange($oldTargeting, $newTargeting)) {
            \Modules\VkAds\app\Jobs\AnalyzeTargetingImpact::dispatch($adGroup)
                ->delay(now()->addHours(6));
        }
    }

    /**
     * Обработка изменения привязки к заказу
     */
    private function handleOrderItemChange(VkAdsAdGroup $adGroup, ?int $oldOrderItemId, ?int $newOrderItemId): void
    {
        Log::info('Ad Group order item changed', [
            'ad_group_id' => $adGroup->id,
            'old_order_item_id' => $oldOrderItemId,
            'new_order_item_id' => $newOrderItemId
        ]);

        // Если привязали к новой строке заказа
        if ($newOrderItemId) {
            $orderItem = \Modules\Accounting\app\Models\CustomerOrderItem::find($newOrderItemId);

            if ($orderItem) {
                Log::info('Ad Group linked to order item', [
                    'ad_group_id' => $adGroup->id,
                    'order_item_id' => $orderItem->id,
                    'product_name' => $orderItem->product_name,
                    'order_number' => $orderItem->customerOrder?->number
                ]);

                // Обновляем название группы на основе продукта, если нужно
                if (config('vkads.auto_update_names_from_orders', true)) {
                    $suggestedName = $this->generateAdGroupNameFromOrderItem($orderItem);

                    if ($suggestedName && $suggestedName !== $adGroup->name) {
                        Log::info("Suggested name change for ad group", [
                            'ad_group_id' => $adGroup->id,
                            'current_name' => $adGroup->name,
                            'suggested_name' => $suggestedName
                        ]);
                    }
                }
            }
        }

        // Если отвязали от строки заказа
        if ($oldOrderItemId && !$newOrderItemId) {
            Log::warning('Ad Group unlinked from order item', [
                'ad_group_id' => $adGroup->id,
                'old_order_item_id' => $oldOrderItemId
            ]);
        }

        // Сбрасываем кэш связи с заказами
        Cache::forget("ad_group_order_item_{$adGroup->id}");
    }

    /**
     * Логирование связи с заказом при создании
     */
    private function logOrderItemConnection(VkAdsAdGroup $adGroup): void
    {
        $orderItem = $adGroup->orderItem;

        if ($orderItem) {
            Log::info('Ad Group created with order item connection', [
                'ad_group_id' => $adGroup->id,
                'order_item_id' => $orderItem->id,
                'product_name' => $orderItem->product_name,
                'order_amount' => $orderItem->amount,
                'order_number' => $orderItem->customerOrder?->number,
                'contract_number' => $orderItem->customerOrder?->contract?->number
            ]);

            // Проверяем соответствие бюджета группы и суммы заказа
            $this->validateBudgetAlignment($adGroup, $orderItem);
        }
    }

    /**
     * Проверка соответствия бюджета и суммы заказа
     */
    private function validateBudgetAlignment(VkAdsAdGroup $adGroup, $orderItem): void
    {
        $campaignBudget = $adGroup->campaign->daily_budget ?? $adGroup->campaign->total_budget;
        $orderAmount = $orderItem->amount;

        if ($campaignBudget && $orderAmount) {
            $budgetDifference = abs($campaignBudget - $orderAmount);
            $budgetDifferencePercent = ($budgetDifference / $orderAmount) * 100;

            // Если разница больше 20%, логируем предупреждение
            if ($budgetDifferencePercent > 20) {
                Log::warning('Significant budget difference between campaign and order', [
                    'ad_group_id' => $adGroup->id,
                    'campaign_budget' => $campaignBudget,
                    'order_amount' => $orderAmount,
                    'difference' => $budgetDifference,
                    'difference_percent' => round($budgetDifferencePercent, 2)
                ]);
            }
        }
    }

    /**
     * Проверка целостности данных группы объявлений
     */
    private function validateAdGroupIntegrity(VkAdsAdGroup $adGroup): void
    {
        // Проверяем связь с кампанией
        if (!$adGroup->campaign) {
            Log::error('Ad Group without campaign', [
                'ad_group_id' => $adGroup->id,
                'campaign_id' => $adGroup->vk_ads_campaign_id
            ]);
        }

        // Проверяем валидность таргетинга
        if (!empty($adGroup->targeting)) {
            $this->validateTargetingStructure($adGroup->targeting, $adGroup);
        }

        // Проверяем валидность размещений
        if (!empty($adGroup->placements)) {
            $this->validatePlacementsStructure($adGroup->placements, $adGroup);
        }

        // Проверяем уникальность VK ad group ID
        $duplicates = VkAdsAdGroup::where('vk_ad_group_id', $adGroup->vk_ad_group_id)
            ->where('id', '!=', $adGroup->id)
            ->count();

        if ($duplicates > 0) {
            Log::error('Duplicate VK ad group ID detected', [
                'ad_group_id' => $adGroup->id,
                'vk_ad_group_id' => $adGroup->vk_ad_group_id,
                'duplicates_count' => $duplicates
            ]);
        }
    }

    /**
     * Валидация структуры таргетинга
     */
    private function validateTargetingStructure(array $targeting, VkAdsAdGroup $adGroup): void
    {
        $requiredFields = ['sex', 'age_from', 'age_to'];
        $missingFields = [];

        foreach ($requiredFields as $field) {
            if (!isset($targeting[$field])) {
                $missingFields[] = $field;
            }
        }

        if (!empty($missingFields)) {
            Log::warning('Ad Group targeting missing required fields', [
                'ad_group_id' => $adGroup->id,
                'missing_fields' => $missingFields,
                'current_targeting' => $targeting
            ]);
        }

        // Проверяем валидность возрастного диапазона
        if (isset($targeting['age_from']) && isset($targeting['age_to'])) {
            if ($targeting['age_from'] > $targeting['age_to']) {
                Log::error('Invalid age range in targeting', [
                    'ad_group_id' => $adGroup->id,
                    'age_from' => $targeting['age_from'],
                    'age_to' => $targeting['age_to']
                ]);
            }
        }
    }

    /**
     * Валидация структуры размещений
     */
    private function validatePlacementsStructure(array $placements, VkAdsAdGroup $adGroup): void
    {
        $validPlacements = ['feed', 'stories', 'apps', 'websites', 'instream'];
        $invalidPlacements = array_diff($placements, $validPlacements);

        if (!empty($invalidPlacements)) {
            Log::error('Invalid placements in ad group', [
                'ad_group_id' => $adGroup->id,
                'invalid_placements' => $invalidPlacements,
                'valid_placements' => $validPlacements
            ]);
        }
    }

    /**
     * Обновление метрик группы объявлений
     */
    private function updateAdGroupMetrics(VkAdsAdGroup $adGroup): void
    {
        try {
            $metrics = [
                'ads_count' => $adGroup->ads()->count(),
                'active_ads_count' => $adGroup->ads()->where('status', 'active')->count(),
                'total_spend' => $adGroup->getTotalSpend(),
                'last_30_days_spend' => $this->getRecentSpend($adGroup, 30),
                'last_7_days_spend' => $this->getRecentSpend($adGroup, 7),
                'avg_bid' => $adGroup->bid,
                'targeting_complexity' => $this->calculateTargetingComplexity($adGroup->targeting),
                'updated_at' => now()
            ];

            Cache::put("vk_ads_ad_group_metrics_{$adGroup->id}", $metrics, now()->addHours(1));

        } catch (\Exception $e) {
            Log::error('Failed to update ad group metrics: ' . $e->getMessage(), [
                'ad_group_id' => $adGroup->id
            ]);
        }
    }

    /**
     * Получение трат за последние N дней
     */
    private function getRecentSpend(VkAdsAdGroup $adGroup, int $days): float
    {
        $fromDate = now()->subDays($days);

        return $adGroup->statistics()
            ->where('stats_date', '>=', $fromDate)
            ->sum('spend');
    }

    /**
     * Расчет сложности таргетинга
     */
    private function calculateTargetingComplexity(?array $targeting): int
    {
        if (!$targeting) {
            return 0;
        }

        $complexity = 0;

        // Базовые параметры
        if (isset($targeting['sex'])) $complexity += 1;
        if (isset($targeting['age_from']) || isset($targeting['age_to'])) $complexity += 1;
        if (isset($targeting['geo']) && !empty($targeting['geo'])) $complexity += count($targeting['geo']);
        if (isset($targeting['interests']) && !empty($targeting['interests'])) $complexity += count($targeting['interests']);
        if (isset($targeting['behaviors']) && !empty($targeting['behaviors'])) $complexity += count($targeting['behaviors']);

        return $complexity;
    }

    /**
     * Анализ изменений таргетинга
     */
    private function analyzeTargetingChanges(?array $oldTargeting, ?array $newTargeting): array
    {
        $changes = [];

        $oldTargeting = $oldTargeting ?? [];
        $newTargeting = $newTargeting ?? [];

        // Сравниваем основные параметры
        $fields = ['sex', 'age_from', 'age_to', 'geo', 'interests', 'behaviors'];

        foreach ($fields as $field) {
            $oldValue = $oldTargeting[$field] ?? null;
            $newValue = $newTargeting[$field] ?? null;

            if ($oldValue !== $newValue) {
                $changes[$field] = [
                    'old' => $oldValue,
                    'new' => $newValue
                ];
            }
        }

        return $changes;
    }

    /**
     * Проверка значительности изменения ставки
     */
    private function isSignificantBidChange(?float $oldBid, ?float $newBid): bool
    {
        if (!$oldBid || !$newBid) {
            return false;
        }

        $changePercent = abs(($newBid - $oldBid) / $oldBid) * 100;
        return $changePercent > 20; // Изменение больше 20%
    }

    /**
     * Проверка значительности изменения таргетинга
     */
    private function isSignificantTargetingChange(?array $oldTargeting, ?array $newTargeting): bool
    {
        $oldComplexity = $this->calculateTargetingComplexity($oldTargeting);
        $newComplexity = $this->calculateTargetingComplexity($newTargeting);

        return abs($oldComplexity - $newComplexity) > 2; // Изменение сложности больше чем на 2
    }

    /**
     * Генерация названия группы на основе строки заказа
     */
    private function generateAdGroupNameFromOrderItem($orderItem): ?string
    {
        if (!$orderItem) {
            return null;
        }

        $productName = $orderItem->product_name;
        $orderNumber = $orderItem->customerOrder?->number;

        // Формируем предлагаемое название
        if ($orderNumber) {
            return "{$productName} (Заказ {$orderNumber})";
        }

        return $productName;
    }

    /**
     * Сброс кэша группы объявлений
     */
    private function invalidateAdGroupCache(VkAdsAdGroup $adGroup): void
    {
        Cache::forget("vk_ads_ad_group_{$adGroup->id}");
        Cache::forget("vk_ads_ad_group_metrics_{$adGroup->id}");
        Cache::tags(['vk-ads-ad-groups'])->flush();
    }

    /**
     * Сброс кэша кампании
     */
    private function invalidateCampaignCache(VkAdsAdGroup $adGroup): void
    {
        Cache::forget("vk_ads_campaign_{$adGroup->vk_ads_campaign_id}");
        Cache::forget("vk_ads_campaign_metrics_{$adGroup->vk_ads_campaign_id}");
        Cache::tags(['vk-ads-campaigns'])->flush();
    }

    /**
     * Логирование действий с группой объявлений для аудита
     */
    private function logAdGroupAction(VkAdsAdGroup $adGroup, string $action, array $changes = []): void
    {
        $logData = [
            'module' => 'VkAds',
            'model' => 'VkAdsAdGroup',
            'action' => $action,
            'ad_group_id' => $adGroup->id,
            'vk_ad_group_id' => $adGroup->vk_ad_group_id,
            'name' => $adGroup->name,
            'campaign_id' => $adGroup->vk_ads_campaign_id,
            'customer_order_item_id' => $adGroup->customer_order_item_id,
            'status' => $adGroup->status,
            'bid' => $adGroup->bid,
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
        if ($adGroup->campaign) {
            $logData['campaign_name'] = $adGroup->campaign->name;
            $logData['account_id'] = $adGroup->campaign->vk_ads_account_id;
        }

        if ($adGroup->orderItem) {
            $logData['order_product_name'] = $adGroup->orderItem->product_name;
            $logData['order_amount'] = $adGroup->orderItem->amount;
        }

        // Записываем в лог аудита
        Log::channel('audit')->info("VkAdsAdGroup.{$action}", $logData);

        // Если есть таблица аудита, записываем туда
        if (class_exists('\App\Models\AuditLog')) {
            \App\Models\AuditLog::create([
                'model_type' => VkAdsAdGroup::class,
                'model_id' => $adGroup->id,
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
