<?php
// Modules/Bitrix24/app/Services/Processors/CustomerOrderSyncProcessor.php

namespace Modules\Bitrix24\app\Services\Processors;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\ContactPerson;
use Modules\Accounting\app\Models\CustomerOrder;
use Modules\Accounting\app\Models\ObjectChangeLog;
use Modules\Accounting\app\Models\UnitOfMeasure;
use Modules\Bitrix24\app\Exceptions\DependencyNotReadyException;
use Modules\Bitrix24\app\Exceptions\ValidationException;

class CustomerOrderSyncProcessor extends AbstractBitrix24Processor
{
    const INVOICE_ENTITY_TYPE_ID = 31;
    const CONTRACT_ENTITY_TYPE_ID = 1064;

    protected array $productPropertiesCache = [];
    protected array $productMapCache = [];

    protected function syncEntity(ObjectChangeLog $change): void
    {
        $order = CustomerOrder::with(['items', 'organization'])->find($change->local_id);

        if (!$order) {
            throw new ValidationException("CustomerOrder not found: {$change->local_id}");
        }

        // Валидация
        $this->validateOrder($order);

        // Проверка минимальной даты синхронизации
        if ($this->isOrderTooOld($order)) {
            throw new ValidationException(
                "Order date before " . config('bitrix24.sync.min_invoice_date', '2025-11-01')
            );
        }

        Log::info("Processing customer order", [
            'guid' => $order->guid_1c,
            'number' => $order->number,
            'date' => $order->date?->format('Y-m-d')
        ]);

        // Разрешение зависимостей
        $dependencies = $this->resolveDependencies($order);

        // Подготовка полей
        $fields = $this->prepareOrderFields($order, $dependencies);

        // Поиск существующего счёта
        $existingInvoiceId = $this->findInvoiceByGuid($order->guid_1c);

        if ($existingInvoiceId) {
            // UPDATE
            $this->updateInvoice($existingInvoiceId, $fields);
            $invoiceId = $existingInvoiceId;
        } else {
            // CREATE
            $invoiceId = $this->createInvoice($fields);
        }

        // Синхронизация товарных позиций
        $this->syncProductRows($invoiceId, $order);

        $change->b24_id = $invoiceId;
    }

    /**
     * Валидация заказа
     */
    protected function validateOrder(CustomerOrder $order): void
    {
        if (empty($order->guid_1c)) {
            throw new ValidationException("Order {$order->id} has no GUID");
        }

        if (empty($order->counterparty_guid_1c)) {
            throw new ValidationException("Order {$order->guid_1c} has no counterparty_guid_1c");
        }
    }

    /**
     * Проверка даты заказа
     */
    protected function isOrderTooOld(CustomerOrder $order): bool
    {
        $minDate = config('bitrix24.sync.min_invoice_date', '2025-11-01');

        return $order->date && $order->date->lt(Carbon::parse($minDate));
    }

    /**
     * Разрешение зависимостей
     */
    protected function resolveDependencies(CustomerOrder $order): array
    {
        // Компания (обязательно)
        $companyId = $this->findCompanyIdByRequisiteGuid($order->counterparty_guid_1c);

        if (!$companyId) {
            throw new DependencyNotReadyException(
                "Company not synced for requisite GUID: {$order->counterparty_guid_1c}"
            );
        }

        // Контакт (опционально)
        $contactId = null;
        $contact = ContactPerson::where('counterparty_guid_1c', $order->counterparty_guid_1c)
            ->where('is_active', true)
            ->first();

        if ($contact && $contact->guid_1c) {
            $contactId = $this->findContactByGuid($contact->guid_1c);
        }

        // Моя компания (опционально)
        $myCompanyId = null;
        if ($order->organization_guid_1c) {
            $myCompanyId = $this->findCompanyIdByRequisiteGuid($order->organization_guid_1c);
        }

        // Договор (опционально)
        $contractId = null;
        if ($order->contract_guid_1c) {
            $contractId = $this->findContractByGuid($order->contract_guid_1c);

            if (!$contractId) {
                Log::warning("Contract not found in B24", [
                    'contract_guid' => $order->contract_guid_1c,
                    'order_number' => $order->number
                ]);
            }
        }

        // Ответственный (опционально)
        $responsibleId = null;
        if ($order->responsible_guid_1c) {
            $responsibleId = $this->findUserIdByGuid($order->responsible_guid_1c);
        }

        return [
            'company_id' => $companyId,
            'contact_id' => $contactId,
            'mycompany_id' => $myCompanyId,
            'contract_id' => $contractId,
            'responsible_id' => $responsibleId,
        ];
    }

    /**
     * Подготовка полей заказа
     */
    protected function prepareOrderFields(CustomerOrder $order, array $dependencies): array
    {
        $title = "Счёт №{$order->number}";
        if ($order->date) {
            $title .= " от " . $order->date->format('d.m.Y');
        }

        $fields = [
            'title' => $title,
            'companyId' => $dependencies['company_id'],
            'opportunity' => (float)$order->amount,
            'currencyId' => 'RUB',
            'isManualOpportunity' => 'Y',
            'xmlId' => $order->guid_1c,
        ];

        if ($order->date) {
            $fields['begindate'] = $order->date->format('Y-m-d');
        }

        if ($dependencies['contact_id']) {
            $fields['contactId'] = $dependencies['contact_id'];
        }

        if ($dependencies['mycompany_id']) {
            $fields['mycompanyId'] = $dependencies['mycompany_id'];
        }

        if ($dependencies['contract_id']) {
            $fields['parentId' . self::CONTRACT_ENTITY_TYPE_ID] = $dependencies['contract_id'];
        }

        if ($dependencies['responsible_id']) {
            $fields['assignedById'] = $dependencies['responsible_id'];
        }

        if (!empty($order->comment)) {
            $fields['comments'] = $this->cleanString($order->comment);
        }

        return $fields;
    }

    /**
     * Создание счёта
     */
    protected function createInvoice(array $fields): int
    {
        $result = $this->b24Service->call('crm.item.add', [
            'entityTypeId' => self::INVOICE_ENTITY_TYPE_ID,
            'fields' => $fields,
        ]);

        if (empty($result['result']['item']['id'])) {
            throw new \Exception("Failed to create invoice: " . json_encode($result));
        }

        $invoiceId = (int)$result['result']['item']['id'];

        Log::info("Invoice created", ['b24_id' => $invoiceId]);

        return $invoiceId;
    }

    /**
     * Обновление счёта
     */
    protected function updateInvoice(int $invoiceId, array $fields): void
    {
        $this->b24Service->call('crm.item.update', [
            'entityTypeId' => self::INVOICE_ENTITY_TYPE_ID,
            'id' => $invoiceId,
            'fields' => $fields,
        ]);

        Log::debug("Invoice updated", ['b24_id' => $invoiceId]);
    }

    /**
     * Синхронизация товарных позиций
     */
    protected function syncProductRows(int $invoiceId, CustomerOrder $order): void
    {
        if ($order->items->isEmpty()) {
            Log::debug("No items to sync for invoice", ['invoice_id' => $invoiceId]);
            return;
        }

        $productRows = [];

        foreach ($order->items as $item) {
            $row = [
                'productName' => $this->cleanString($item->content)
                    ?: $this->cleanString($item->product_name)
                        ?: 'Товар/Услуга',
                'quantity' => (float)$item->quantity,
                'price' => (float)$item->price,
                'discountTypeId' => 1,
                'discountRate' => 0,
            ];

            // Привязка к товару из каталога
            if ($item->product_guid_1c) {
                $productId = $this->findProductIdByGuid($item->product_guid_1c);
                if ($productId) {
                    $row['productId'] = $productId;
                }
            }

            // НДС
            $taxData = $this->calculateTax($item, $order->amount_includes_vat);
            if ($taxData) {
                $row['taxRate'] = $taxData['rate'];
                $row['taxIncluded'] = $taxData['included'];
            }

            // Единица измерения
            if ($item->unit_guid_1c) {
                $measureCode = $this->getMeasureCode($item->unit_guid_1c);
                if ($measureCode) {
                    $row['measureCode'] = $measureCode;
                }
            }

            $productRows[] = $row;
        }

        try {
            $this->b24Service->call('crm.item.productrow.set', [
                'ownerType' => 'SI',
                'ownerId' => $invoiceId,
                'productRows' => $productRows,
            ]);

            Log::debug("Product rows synced", [
                'invoice_id' => $invoiceId,
                'rows_count' => count($productRows)
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to sync product rows", [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Расчёт НДС
     */
    protected function calculateTax($item, bool $taxIncluded): ?array
    {
        if (empty($item->vat_amount) || $item->vat_amount <= 0 || $item->amount <= 0) {
            return null;
        }

        if ($taxIncluded) {
            // НДС включён в цену
            $baseAmount = $item->amount - $item->vat_amount;
            $taxRate = ($baseAmount > 0) ? ($item->vat_amount / $baseAmount) * 100 : 0;
        } else {
            // НДС сверху
            $taxRate = ($item->vat_amount / $item->amount) * 100;
        }

        return [
            'rate' => round($taxRate, 2),
            'included' => $taxIncluded ? 'Y' : 'N'
        ];
    }

    /**
     * Поиск счёта по GUID
     */
    protected function findInvoiceByGuid(string $guid): ?int
    {
        $response = $this->b24Service->call('crm.item.list', [
            'entityTypeId' => self::INVOICE_ENTITY_TYPE_ID,
            'filter' => ['xmlId' => $guid],
            'select' => ['id'],
            'limit' => 1,
        ]);

        return $response['result']['items'][0]['id'] ?? null;
    }

    /**
     * Поиск договора по GUID
     */
    protected function findContractByGuid(string $guid): ?int
    {
        $response = $this->b24Service->call('crm.item.list', [
            'entityTypeId' => self::CONTRACT_ENTITY_TYPE_ID,
            'filter' => ['UF_CRM_19_GUID_1C' => $guid],
            'select' => ['id'],
            'limit' => 1,
            'useOriginalUfNames' => 'Y'
        ]);

        return $response['result']['items'][0]['id'] ?? null;
    }

    /**
     * Поиск товара по GUID
     */
    protected function findProductIdByGuid(string $guid): ?int
    {
        if (isset($this->productMapCache[$guid])) {
            return $this->productMapCache[$guid];
        }

        $propId = $this->getProductPropertyId('GUID_1C');
        if (!$propId) {
            return null;
        }

        $response = $this->b24Service->call('crm.product.list', [
            'filter' => ['PROPERTY_' . $propId => $guid],
            'select' => ['ID'],
            'limit' => 1
        ]);

        $productId = $response['result'][0]['ID'] ?? null;

        if ($productId) {
            $this->productMapCache[$guid] = (int)$productId;
            return (int)$productId;
        }

        return null;
    }

    /**
     * Получение ID свойства товара
     */
    protected function getProductPropertyId(string $code): ?int
    {
        if (isset($this->productPropertiesCache[$code])) {
            return $this->productPropertiesCache[$code];
        }

        return Cache::remember("b24:product_property:{$code}", 3600, function () use ($code) {
            $response = $this->b24Service->call('crm.product.property.list');

            foreach ($response['result'] ?? [] as $property) {
                $this->productPropertiesCache[$property['CODE']] = (int)$property['ID'];
            }

            return $this->productPropertiesCache[$code] ?? null;
        });
    }

    /**
     * Получение кода единицы измерения
     */
    protected function getMeasureCode(string $unitGuid): ?int
    {
        $unit = UnitOfMeasure::where('guid_1c', $unitGuid)->first();

        return $unit?->code ? (int)$unit->code : null;
    }
}
