<?php

namespace Modules\VkAds\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\Accounting\app\Models\Contract;
use Modules\VkAds\app\Services\VkAdsAccountService;

class CreateVkAdsAccountCommand extends Command
{
    protected $signature = 'vk-ads:create-account {contract-id} {--name= : Название кабинета}';

    protected $description = 'Создать или обновить рекламный кабинет VK Ads из договора';

    public function handle(VkAdsAccountService $accountService): int
    {
        $contractId = (int) $this->argument('contract-id');

        try {
            // Показываем информацию о договоре
            $contract = Contract::with('counterparty')->findOrFail($contractId);
            $this->info('Работа с кабинетом для:');
            $this->info("  Договор: {$contract->fullName}");
            $this->info("  Контрагент: {$contract->counterparty->name}");
            $this->info("  ИНН: {$contract->counterparty->inn}");

            $additionalData = [];
            if ($name = $this->option('name')) {
                $additionalData['account_name'] = $name;
            }

            $account = $accountService->createOrUpdateAccountFromContract($contractId, $additionalData);

            $this->info('✅ Операция завершена успешно!');
            $this->info("  ID: {$account->id}");
            $this->info("  VK Account ID: {$account->vk_account_id}");
            $this->info("  Название: {$account->account_name}");
            $this->info("  Статус: {$account->account_status}");
            $this->info("  Договор: {$account->contract->number}");

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Ошибка работы с кабинетом: '.$e->getMessage());

            if ($this->option('verbose')) {
                $this->line($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }
}
