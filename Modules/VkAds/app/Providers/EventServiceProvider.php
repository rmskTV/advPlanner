<?php

namespace Modules\VkAds\app\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
// === СОБЫТИЯ ===
use Modules\VkAds\app\Events\ActGenerated;
use Modules\VkAds\app\Events\AdCreated;
use Modules\VkAds\app\Events\BudgetExhausted;
use Modules\VkAds\app\Events\CampaignPerformanceAlert;
use Modules\VkAds\app\Events\CampaignStatusChanged;
use Modules\VkAds\app\Events\CreativeUploaded;
use Modules\VkAds\app\Events\ModerationCompleted;
use Modules\VkAds\app\Events\SyncCompleted;
use Modules\VkAds\app\Events\SyncFailed;
// === СЛУШАТЕЛИ ===
use Modules\VkAds\app\Listeners\CheckCampaignBudget;
use Modules\VkAds\app\Listeners\CheckDataConsistency;
use Modules\VkAds\app\Listeners\LogActGeneration;
use Modules\VkAds\app\Listeners\LogAdCreation;
use Modules\VkAds\app\Listeners\LogBudgetEvent;
use Modules\VkAds\app\Listeners\LogCampaignStatusChange;
use Modules\VkAds\app\Listeners\LogModerationResult;
use Modules\VkAds\app\Listeners\LogPerformanceAlert;
use Modules\VkAds\app\Listeners\LogSyncResult;
use Modules\VkAds\app\Listeners\NotifyActGenerated;
use Modules\VkAds\app\Listeners\NotifyAdministrators;
use Modules\VkAds\app\Listeners\PauseCampaignOnBudgetExhaustion;
use Modules\VkAds\app\Listeners\ProcessCreativeVariants;
use Modules\VkAds\app\Listeners\RetryFailedSync;
use Modules\VkAds\app\Listeners\SendBudgetAlert;
use Modules\VkAds\app\Listeners\SendModerationNotification;
use Modules\VkAds\app\Listeners\SendPerformanceAlert;
use Modules\VkAds\app\Listeners\UpdateAccountingCache;
use Modules\VkAds\app\Listeners\UpdateAdGroupCache;
use Modules\VkAds\app\Listeners\UpdateCampaignCache;
use Modules\VkAds\app\Listeners\UpdateCreativeCache;
use Modules\VkAds\app\Listeners\UpdateStatisticsCache;
use Modules\VkAds\app\Listeners\ValidateAdCreatives;
use Modules\VkAds\app\Listeners\ValidateCreativeRequirements;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Карта событий и их слушателей
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        // === СОБЫТИЯ МОДЕРАЦИИ ===
        ModerationCompleted::class => [
            SendModerationNotification::class,
            LogModerationResult::class,
        ],

        // === СОБЫТИЯ КАМПАНИЙ ===
        CampaignStatusChanged::class => [
            UpdateCampaignCache::class,
            LogCampaignStatusChange::class,
            CheckCampaignBudget::class,
        ],

        // === СОБЫТИЯ КРЕАТИВОВ ===
        CreativeUploaded::class => [
            ProcessCreativeVariants::class,
            ValidateCreativeRequirements::class,
            UpdateCreativeCache::class,
        ],

        // === СОБЫТИЯ ОБЪЯВЛЕНИЙ ===
        AdCreated::class => [
            ValidateAdCreatives::class,
            UpdateAdGroupCache::class,
            LogAdCreation::class,
        ],

        // === БЮДЖЕТНЫЕ СОБЫТИЯ ===
        BudgetExhausted::class => [
            SendBudgetAlert::class,
            PauseCampaignOnBudgetExhaustion::class,
            LogBudgetEvent::class,
        ],

        // === СОБЫТИЯ ПРОИЗВОДИТЕЛЬНОСТИ ===
        CampaignPerformanceAlert::class => [
            SendPerformanceAlert::class,
            LogPerformanceAlert::class,
        ],

        // === СОБЫТИЯ ДОКУМЕНТООБОРОТА ===
        ActGenerated::class => [
            NotifyActGenerated::class,
            UpdateAccountingCache::class,
            LogActGeneration::class,
        ],

        // === СОБЫТИЯ СИНХРОНИЗАЦИИ ===
        SyncCompleted::class => [
            UpdateStatisticsCache::class,
            LogSyncResult::class,
            CheckDataConsistency::class,
        ],

        SyncFailed::class => [
            LogSyncResult::class,
            NotifyAdministrators::class,
            RetryFailedSync::class,
        ],
    ];

    /**
     * Регистрация дополнительных событий
     */
    public function boot(): void
    {
        parent::boot();

        // Регистрируем глобальные события модели
        $this->registerModelEvents();

        // Регистрируем наблюдатели
        $this->registerObservers();

        // Регистрируем кастомные события
        $this->registerCustomEvents();
    }

    /**
     * Регистрация событий моделей Eloquent
     */
    private function registerModelEvents(): void
    {
        // === СОБЫТИЯ VkAdsAccount ===
        \Modules\VkAds\app\Models\VkAdsAccount::created(function ($account) {
            \Log::info('VK Ads Account created', [
                'account_id' => $account->id,
                'vk_account_id' => $account->vk_account_id,
                'account_name' => $account->account_name,
                'account_type' => $account->account_type,
            ]);

            // Сбрасываем кэш аккаунтов
            \Cache::tags(['vk-ads-accounts'])->flush();
        });

        \Modules\VkAds\app\Models\VkAdsAccount::updated(function ($account) {
            if ($account->isDirty('account_status')) {
                \Log::info('VK Ads Account status changed', [
                    'account_id' => $account->id,
                    'account_name' => $account->account_name,
                    'old_status' => $account->getOriginal('account_status'),
                    'new_status' => $account->account_status,
                ]);
            }

            if ($account->isDirty('balance')) {
                \Log::info('VK Ads Account balance updated', [
                    'account_id' => $account->id,
                    'old_balance' => $account->getOriginal('balance'),
                    'new_balance' => $account->balance,
                ]);
            }
        });

        \Modules\VkAds\app\Models\VkAdsAccount::deleting(function ($account) {
            \Log::warning('VK Ads Account being deleted', [
                'account_id' => $account->id,
                'account_name' => $account->account_name,
                'campaigns_count' => $account->campaigns()->count(),
            ]);
        });

        // === СОБЫТИЯ VkAdsCampaign ===
        \Modules\VkAds\app\Models\VkAdsCampaign::created(function ($campaign) {
            \Log::info('VK Ads Campaign created', [
                'campaign_id' => $campaign->id,
                'vk_campaign_id' => $campaign->vk_campaign_id,
                'name' => $campaign->name,
                'account_id' => $campaign->vk_ads_account_id,
                'daily_budget' => $campaign->daily_budget,
            ]);
        });

        \Modules\VkAds\app\Models\VkAdsCampaign::updated(function ($campaign) {
            // Событие изменения статуса
            if ($campaign->isDirty('status')) {
                CampaignStatusChanged::dispatch(
                    $campaign,
                    $campaign->getOriginal('status'),
                    $campaign->status
                );
            }

            // Событие изменения бюджета
            if ($campaign->isDirty(['daily_budget', 'total_budget'])) {
                \Log::info('Campaign budget updated', [
                    'campaign_id' => $campaign->id,
                    'old_daily_budget' => $campaign->getOriginal('daily_budget'),
                    'new_daily_budget' => $campaign->daily_budget,
                    'old_total_budget' => $campaign->getOriginal('total_budget'),
                    'new_total_budget' => $campaign->total_budget,
                ]);
            }
        });

        // === СОБЫТИЯ VkAdsCreative ===
        \Modules\VkAds\app\Models\VkAdsCreative::created(function ($creative) {
            CreativeUploaded::dispatch($creative);
        });

        \Modules\VkAds\app\Models\VkAdsCreative::updated(function ($creative) {
            if ($creative->isDirty('moderation_status')) {
                ModerationCompleted::dispatch(
                    $creative,
                    $creative->getOriginal('moderation_status'),
                    $creative->moderation_status
                );
            }
        });

        // === СОБЫТИЯ VkAdsAd ===
        \Modules\VkAds\app\Models\VkAdsAd::created(function ($ad) {
            AdCreated::dispatch($ad);
        });

        \Modules\VkAds\app\Models\VkAdsAd::updated(function ($ad) {
            if ($ad->isDirty('moderation_status')) {
                ModerationCompleted::dispatch(
                    $ad,
                    $ad->getOriginal('moderation_status'),
                    $ad->moderation_status
                );
            }

            if ($ad->isDirty('status')) {
                \Log::info('Ad status changed', [
                    'ad_id' => $ad->id,
                    'ad_name' => $ad->name,
                    'old_status' => $ad->getOriginal('status'),
                    'new_status' => $ad->status,
                ]);
            }
        });

        // === СОБЫТИЯ VkAdsAdGroup ===
        \Modules\VkAds\app\Models\VkAdsAdGroup::created(function ($adGroup) {
            \Log::info('VK Ads Ad Group created', [
                'ad_group_id' => $adGroup->id,
                'vk_ad_group_id' => $adGroup->vk_ad_group_id,
                'name' => $adGroup->name,
                'campaign_id' => $adGroup->vk_ads_campaign_id,
                'customer_order_item_id' => $adGroup->customer_order_item_id,
            ]);
        });

        // === СОБЫТИЯ VkAdsStatistics ===
        \Modules\VkAds\app\Models\VkAdsStatistics::created(function ($stats) {
            // Проверяем, не превышен ли бюджет после добавления новой статистики
            $adGroup = $stats->adGroup;
            $campaign = $adGroup->campaign;

            if ($campaign->daily_budget || $campaign->total_budget) {
                $totalSpend = $campaign->getTotalSpend();
                $budgetLimit = $campaign->daily_budget ?? $campaign->total_budget;

                if ($totalSpend >= $budgetLimit * 0.9) { // 90% от бюджета
                    BudgetExhausted::dispatch($campaign, $totalSpend, $budgetLimit);
                }
            }
        });

        // === СОБЫТИЯ VkAdsToken ===
        \Modules\VkAds\app\Models\VkAdsToken::created(function ($token) {
            \Log::info('VK Ads Token created', [
                'token_id' => $token->id,
                'account_id' => $token->vk_ads_account_id,
                'expires_at' => $token->expires_at,
            ]);
        });

        \Modules\VkAds\app\Models\VkAdsToken::updated(function ($token) {
            if ($token->isDirty('is_active') && ! $token->is_active) {
                \Log::warning('VK Ads Token deactivated', [
                    'token_id' => $token->id,
                    'account_id' => $token->vk_ads_account_id,
                    'reason' => $token->isExpired() ? 'expired' : 'manual',
                ]);
            }
        });
    }

    /**
     * Регистрация наблюдателей моделей
     */
    private function registerObservers(): void
    {
        \Modules\VkAds\app\Models\VkAdsAccount::observe(\Modules\VkAds\app\Observers\VkAdsAccountObserver::class);
        \Modules\VkAds\app\Models\VkAdsCampaign::observe(\Modules\VkAds\app\Observers\VkAdsCampaignObserver::class);
        \Modules\VkAds\app\Models\VkAdsCreative::observe(\Modules\VkAds\app\Observers\VkAdsCreativeObserver::class);
        \Modules\VkAds\app\Models\VkAdsAd::observe(\Modules\VkAds\app\Observers\VkAdsAdObserver::class);
        \Modules\VkAds\app\Models\VkAdsAdGroup::observe(\Modules\VkAds\app\Observers\VkAdsAdGroupObserver::class);
        \Modules\VkAds\app\Models\VkAdsStatistics::observe(\Modules\VkAds\app\Observers\VkAdsStatisticsObserver::class);
    }

    /**
     * Регистрация кастомных событий и глобальных слушателей
     */
    private function registerCustomEvents(): void
    {
        // === ГЛОБАЛЬНЫЕ СЛУШАТЕЛИ ===

        // Слушатель для всех событий модерации
        \Event::listen('vk-ads.moderation.*', function ($eventName, $data) {
            \Log::info("VK Ads moderation event: {$eventName}", $data);
        });

        // Слушатель для всех API ошибок
        \Event::listen('vk-ads.api.error', function ($error, $context) {
            \Log::error("VK Ads API Error: {$error}", $context);

            // Можно отправить уведомление администраторам
            if ($this->isCriticalError($error)) {
                $this->notifyAdministrators($error, $context);
            }
        });

        // Слушатель для превышения лимитов API
        \Event::listen('vk-ads.rate-limit.exceeded', function ($account, $endpoint) {
            \Log::warning('VK Ads rate limit exceeded', [
                'account_id' => $account->id,
                'endpoint' => $endpoint,
                'timestamp' => now(),
            ]);
        });

        // === ИНТЕГРАЦИЯ С ACCOUNTING МОДУЛЕМ ===

        // Слушаем события создания заказов клиентов
        if (class_exists('\Modules\Accounting\app\Events\CustomerOrderCreated')) {
            \Event::listen('\Modules\Accounting\app\Events\CustomerOrderCreated', function ($order) {
                // Автоматически проверяем, есть ли в заказе рекламные услуги
                $this->checkForAdvertisingServices($order);
            });
        }

        // Слушаем события создания реализации
        if (class_exists('\Modules\Accounting\app\Events\SaleCreated')) {
            \Event::listen('\Modules\Accounting\app\Events\SaleCreated', function ($sale) {
                // Проверяем, связана ли реализация с VK Ads статистикой
                $this->processSaleWithVkAds($sale);
            });
        }

        // === СИСТЕМНЫЕ СОБЫТИЯ ===

        // Событие запуска приложения
        \Event::listen('app.booted', function () {
            if (config('vkads.logging.enabled')) {
                \Log::info('VK Ads module loaded successfully');
            }
        });

        // Событие завершения работы приложения
        \Event::listen('app.terminating', function () {
            // Очищаем временные данные
            $this->cleanupTemporaryData();
        });
    }

    /**
     * Проверка критичности ошибки API
     */
    private function isCriticalError(string $error): bool
    {
        $criticalErrors = [
            'authentication_failed',
            'account_blocked',
            'api_access_revoked',
            'invalid_token',
        ];

        foreach ($criticalErrors as $criticalError) {
            if (str_contains(strtolower($error), $criticalError)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Уведомление администраторов о критических ошибках
     */
    private function notifyAdministrators(string $error, array $context): void
    {
        $adminEmails = config('vkads.notifications.admin_emails', []);

        if (empty($adminEmails)) {
            return;
        }

        try {
            \Mail::send('vk-ads::emails.critical-error', [
                'error' => $error,
                'context' => $context,
                'timestamp' => now(),
                'server' => request()->server(),
            ], function ($message) use ($adminEmails) {
                $message->to($adminEmails)
                    ->subject('VK Ads: Критическая ошибка API');
            });
        } catch (\Exception $e) {
            \Log::error('Failed to notify administrators: '.$e->getMessage());
        }
    }

    /**
     * Проверка заказа на наличие рекламных услуг
     */
    private function checkForAdvertisingServices($order): void
    {
        try {
            $advertisingKeywords = config('vkads.accounting_integration.advertising_keywords', [
                'реклама', 'рекламный', 'преролл', 'аудиоролик',
                'баннер', 'таргет', 'продвижение', 'vk ads', 'instream',
            ]);

            foreach ($order->items as $item) {
                $productName = mb_strtolower($item->product_name ?? '');

                foreach ($advertisingKeywords as $keyword) {
                    if (str_contains($productName, $keyword)) {
                        \Log::info('Advertising service detected in order', [
                            'order_id' => $order->id,
                            'item_id' => $item->id,
                            'product_name' => $item->product_name,
                            'detected_keyword' => $keyword,
                        ]);

                        // Можно запустить Job для автоматического создания кампаний
                        if (config('vkads.accounting_integration.auto_create_campaigns')) {
                            \Modules\VkAds\app\Jobs\CreateCampaignFromOrderItem::dispatch($item);
                        }

                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error checking advertising services in order: '.$e->getMessage());
        }
    }

    /**
     * Обработка реализации с VK Ads данными
     */
    private function processSaleWithVkAds($sale): void
    {
        try {
            // Проверяем, есть ли в строках реализации данные VK Ads
            $hasVkAdsData = $sale->items()
                ->whereHas('vkAdsStatistics')
                ->exists();

            if ($hasVkAdsData) {
                ActGenerated::dispatch(
                    $sale,
                    $sale->contract ?? new \Modules\Accounting\app\Models\Contract,
                    $this->getCampaignStatsFromSale($sale)
                );
            }
        } catch (\Exception $e) {
            \Log::error('Error processing sale with VK Ads data: '.$e->getMessage());
        }
    }

    /**
     * Получение статистики кампаний из реализации
     */
    private function getCampaignStatsFromSale($sale): array
    {
        $stats = [];

        foreach ($sale->items as $item) {
            $vkStats = $item->vkAdsStatistics;

            if ($vkStats->isNotEmpty()) {
                $stats[] = [
                    'item_id' => $item->id,
                    'product_name' => $item->product_name,
                    'amount' => $item->amount,
                    'total_impressions' => $vkStats->sum('impressions'),
                    'total_clicks' => $vkStats->sum('clicks'),
                    'total_spend' => $vkStats->sum('spend'),
                ];
            }
        }

        return $stats;
    }

    /**
     * Очистка временных данных
     */
    private function cleanupTemporaryData(): void
    {
        try {
            // Очищаем истекшие токены
            \Modules\VkAds\app\Models\VkAdsToken::where('expires_at', '<', now())
                ->where('is_active', true)
                ->update(['is_active' => false]);

            // Очищаем старые логи событий (если есть таблица)
            // EventLog::where('created_at', '<', now()->subDays(30))->delete();

        } catch (\Exception $e) {
            \Log::error('Error during cleanup: '.$e->getMessage());
        }
    }

    /**
     * Получение всех событий для отладки
     */
    public function listens(): array
    {
        return $this->listen;
    }

    /**
     * Проверка, зарегистрировано ли событие
     */
    public function hasListeners(string $event): bool
    {
        return isset($this->listen[$event]) && ! empty($this->listen[$event]);
    }

    /**
     * Получение слушателей для события
     */
    public function getListeners(string $event): array
    {
        return $this->listen[$event] ?? [];
    }

    /**
     * Регистрация нового слушателя во время выполнения
     */
    public function addListener(string $event, string $listener): void
    {
        if (! isset($this->listen[$event])) {
            $this->listen[$event] = [];
        }

        if (! in_array($listener, $this->listen[$event])) {
            $this->listen[$event][] = $listener;
        }
    }

    /**
     * Удаление слушателя
     */
    public function removeListener(string $event, string $listener): void
    {
        if (isset($this->listen[$event])) {
            $this->listen[$event] = array_filter(
                $this->listen[$event],
                fn ($l) => $l !== $listener
            );
        }
    }

    /**
     * Получение статистики по событиям (для мониторинга)
     */
    public function getEventStatistics(): array
    {
        return [
            'total_events' => count($this->listen),
            'total_listeners' => array_sum(array_map('count', $this->listen)),
            'events_with_multiple_listeners' => count(array_filter($this->listen, fn ($listeners) => count($listeners) > 1)),
            'most_listened_event' => $this->getMostListenedEvent(),
            'events_by_category' => $this->categorizeEvents(),
        ];
    }

    private function getMostListenedEvent(): ?string
    {
        if (empty($this->listen)) {
            return null;
        }

        $maxListeners = max(array_map('count', $this->listen));

        foreach ($this->listen as $event => $listeners) {
            if (count($listeners) === $maxListeners) {
                return $event;
            }
        }

        return null;
    }

    private function categorizeEvents(): array
    {
        $categories = [
            'moderation' => 0,
            'campaign' => 0,
            'creative' => 0,
            'ad' => 0,
            'budget' => 0,
            'sync' => 0,
            'performance' => 0,
            'document' => 0,
        ];

        foreach (array_keys($this->listen) as $event) {
            $eventName = strtolower(class_basename($event));

            if (str_contains($eventName, 'moderation')) {
                $categories['moderation']++;
            } elseif (str_contains($eventName, 'campaign')) {
                $categories['campaign']++;
            } elseif (str_contains($eventName, 'creative')) {
                $categories['creative']++;
            } elseif (str_contains($eventName, 'ad')) {
                $categories['ad']++;
            } elseif (str_contains($eventName, 'budget')) {
                $categories['budget']++;
            } elseif (str_contains($eventName, 'sync')) {
                $categories['sync']++;
            } elseif (str_contains($eventName, 'performance')) {
                $categories['performance']++;
            } elseif (str_contains($eventName, 'act')) {
                $categories['document']++;
            }
        }

        return $categories;
    }
}
