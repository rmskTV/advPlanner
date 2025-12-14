<?php

namespace Modules\Bitrix24\app\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\ContactPerson;
use Modules\Accounting\app\Models\CustomerOrder;
use Modules\Accounting\app\Models\ObjectChangeLog;
use Modules\Accounting\app\Models\UnitOfMeasure;

class CustomerOrderSyncProcessor
{
    protected Bitrix24Service $b24Service;

    const INVOICE_ENTITY_TYPE_ID = 31;
    const REQUISITE_GUID_FIELD = 'UF_CRM_GUID_1C';
    const USER_GUID_FIELD = 'UF_USR_1C_GUID';
    const MIN_SYNC_DATE = '2025-11-01';
    const CONTRACT_ENTITY_TYPE_ID = 1064;

    protected ?array $usersCache = null;
    protected array $requisiteMapCache = [];
    protected array $productMapCache = [];
    protected ?array $productPropertiesCache = null;
    protected array $contractMapCache = [];
    public function __construct(Bitrix24Service $b24Service)
    {
        $this->b24Service = $b24Service;
    }

    public function processCustomerOrder(ObjectChangeLog $change): void
    {
        $order = CustomerOrder::with(['items', 'organization'])->find($change->local_id);

        if (!$order) {
            throw new \Exception("CustomerOrder not found: {$change->local_id}");
        }

        if (empty($order->guid_1c)) {
            $change->status = 'skipped';
            $change->error = 'Missing GUID_1C';
            $change->save();
            return;
        }

        if (empty($order->counterparty_guid_1c)) {
            throw new \Exception("CustomerOrder {$order->guid_1c} has no counterparty_guid_1c");
        }

        Log::info("Processing CustomerOrder", ['guid' => $order->guid_1c, 'number' => $order->number]);
        if ($order->date && $order->date->lt(Carbon::parse(self::MIN_SYNC_DATE))) {
            Log::info("Skipping old invoice (before " . self::MIN_SYNC_DATE . ")", [
                'guid' => $order->guid_1c,
                'number' => $order->number,
                'date' => $order->date->format('Y-m-d')
            ]);

            // Помечаем как обработанный, но не переносим
            $change->status = 'processed';
            $change->b24_id = null; // или можно оставить пустым
            $change->error = 'Date before ' . self::MIN_SYNC_DATE . ' - skipped';
            //$change->sent_at = now();
            $change->save();

            return;
        }
        // Находим зависимости по GUID → получаем B24 ID
        $b24CompanyId = $this->findCompanyIdByRequisiteGuid($order->counterparty_guid_1c);
        if (!$b24CompanyId) {
            throw new \Exception("B24 Company not found for GUID {$order->counterparty_guid_1c}");
        }

        $b24ContactId = $this->findContactIdForCounterparty($order->counterparty_guid_1c);
        $b24MyCompanyId = $order->organization_guid_1c
            ? $this->findCompanyIdByRequisiteGuid($order->organization_guid_1c)
            : null;

        $b24ContractId = null;
        if (!empty($order->contract_guid_1c)) {
            $b24ContractId = $this->findContractIdByGuid($order->contract_guid_1c);

            if (!$b24ContractId) {
                Log::warning("Contract not found in B24", [
                    'contract_guid' => $order->contract_guid_1c,
                    'order_number' => $order->number
                ]);
            }
        }

        // Подготавливаем поля — связи через B24 ID
        $fields = $this->mapOrderToB24Fields($order, $b24CompanyId, $b24ContactId, $b24MyCompanyId, $b24ContractId);

        // Ищем существующий счёт по xmlId (GUID)
        $existingInvoiceId = $this->findInvoiceIdByGuid($order->guid_1c);

        if ($existingInvoiceId) {
            $this->b24Service->call('crm.item.update', [
                'entityTypeId' => self::INVOICE_ENTITY_TYPE_ID,
                'id' => $existingInvoiceId,
                'fields' => $fields,
            ]);
            $b24InvoiceId = $existingInvoiceId;
        } else {
            $result = $this->b24Service->call('crm.item.add', [
                'entityTypeId' => self::INVOICE_ENTITY_TYPE_ID,
                'fields' => $fields,
            ]);
            $b24InvoiceId = $result['result']['item']['id'] ?? null;

            if (!$b24InvoiceId) {
                throw new \Exception("Failed to create invoice: " . json_encode($result));
            }
        }

        // Синхронизируем товарные позиции
        $this->syncProductRows($b24InvoiceId, $order);

        $change->b24_id = $b24InvoiceId;
        $change->markProcessed();

        Log::info("CustomerOrder synced", ['b24_id' => $b24InvoiceId]);
    }


    /**
     * НОВЫЙ МЕТОД: Поиск договора по GUID_1C
     */
    protected function findContractIdByGuid(?string $guid): ?int
    {
        if (empty($guid)) {
            return null;
        }

        // Проверяем кэш
        if (isset($this->contractMapCache[$guid])) {
            return $this->contractMapCache[$guid];
        }

        // Ищем в смарт-процессе Договоры по полю ufCrm19_GUID_1C
        // (19 - это ID типа для полей, 1064 - entityTypeId самого процесса)
        $response = $this->b24Service->call('crm.item.list', [
            'entityTypeId' => self::CONTRACT_ENTITY_TYPE_ID,
            'filter' => ['UF_CRM_19_GUID_1C' => $guid],
            //'select' => ['id'],
            'limit' => 1,
            'useOriginalUfNames' => 'Y'
        ]);

        $contractId = $response['result']['items'][0]['id'] ?? null;

        if ($contractId) {
            $this->contractMapCache[$guid] = (int)$contractId;
            return (int)$contractId;
        }

        return null;
    }

    protected function mapOrderToB24Fields(
        CustomerOrder $order,
        int $b24CompanyId,
        ?int $b24ContactId,
        ?int $b24MyCompanyId,
        ?int $b24ContractId
    ): array {
        $title = "Счёт №{$order->number}";
        if ($order->date) {
            $title .= " от " . $order->date->format('d.m.Y');
        }

        $fields = [
            'title' => $title,
            'companyId' => $b24CompanyId,
            'opportunity' => (float)$order->amount,
            'currencyId' => 'RUB',
            'isManualOpportunity' => 'Y',
            // ← ИСПОЛЬЗУЕМ СТАНДАРТНОЕ ПОЛЕ xmlId для GUID
            'xmlId' => $order->guid_1c,
        ];

        if ($order->date) {
            $fields['begindate'] = $order->date->format('Y-m-d');
        }

        if ($b24ContactId) {
            $fields['contactId'] = $b24ContactId;
        }

        if ($b24MyCompanyId) {
            $fields['mycompanyId'] = $b24MyCompanyId;
        }

        // Договор
        if ($b24ContractId) {
            $fields['parentId' . self::CONTRACT_ENTITY_TYPE_ID] = $b24ContractId;

            Log::debug("Linking invoice to contract", [
                'contract_id' => $b24ContractId,
                'field_name' => 'parentId' . self::CONTRACT_ENTITY_TYPE_ID
            ]);
        }
        // Ответственный
        if ($order->responsible_guid_1c) {
            $responsibleId = $this->findUserIdByGuid($order->responsible_guid_1c);
            if ($responsibleId) {
                $fields['assignedById'] = $responsibleId;
            }
        }

        if (!empty($order->comment)) {
            $fields['comments'] = $this->cleanString($order->comment);
        }

        return $fields;
    }

    protected function syncProductRows(int $b24InvoiceId, CustomerOrder $order): void
    {
        if ($order->items->isEmpty()) {
            return;
        }

        $productRows = [];

        foreach ($order->items as $item) {
            $row = [
                'productName' => $this->cleanString($item->content) ?: $this->cleanString($item->product_name) ?:'Товар/Услуга',
                'quantity' => (float)$item->quantity,
                'price' => (float)$item->price,
                'discountTypeId' => 1,
                'discountRate' => 0,
            ];

            // Пытаемся привязать к товару из каталога B24
            $b24ProductId = $this->findProductIdByGuid($item->product_guid_1c);
            if ($b24ProductId) {
                $row['productId'] = $b24ProductId;
            }

            // НДС
            if (!empty($item->vat_amount) && $item->vat_amount > 0 && $item->amount > 0) {
                if ($order->amount_includes_vat) {
                    // НДС ВКЛЮЧЕН в цену: НДС% = (НДС / База) * 100
                    // где База = Цена - НДС
                    $baseAmount = $item->amount - $item->vat_amount;
                    if ($baseAmount > 0) {
                        $taxRate = ($item->vat_amount / $baseAmount) * 100;
                    } else {
                        $taxRate = 0;
                    }
                } else {
                    // НДС НЕ включен (сверху): НДС% = (НДС / Цена) * 100
                    $taxRate = ($item->vat_amount / $item->amount) * 100;
                }

                // Округляем до стандартных ставок РФ (0, 10, 20)
//                if ($taxRate < 5) {
//                    $roundedRate = 0;
//                } elseif ($taxRate < 15) {
//                    $roundedRate = 10;
//                } else {
//                    $roundedRate = 20;
//                }

                $row['taxRate'] = $taxRate;
                $row['taxIncluded'] = $order->amount_includes_vat ? 'Y' : 'N';
            }

            // Единица измерения
            $measureCode = $this->getMeasureCode($item->unit_guid_1c);
            if ($measureCode) {
                $row['measureCode'] = $measureCode;
            }

            $productRows[] = $row;
        }

        try {
            $this->b24Service->call('crm.item.productrow.set', [
                'ownerType' => 'SI',
                'ownerId' => $b24InvoiceId,
                'productRows' => $productRows,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to sync product rows: " . $e->getMessage(), [
                'invoice_id' => $b24InvoiceId
            ]);
        }
    }

    // =========================================================================
    // ПОИСК ПО GUID → B24 ID
    // =========================================================================

    /**
     * Ищем счёт по стандартному полю xmlId
     */
    protected function findInvoiceIdByGuid(string $guid): ?int
    {
        $response = $this->b24Service->call('crm.item.list', [
            'entityTypeId' => self::INVOICE_ENTITY_TYPE_ID,
            'filter' => ['xmlId' => $guid], // ← стандартное поле
            'select' => ['id'],
            'limit' => 1,
        ]);

        return $response['result']['items'][0]['id'] ?? null;
    }

    protected function findCompanyIdByRequisiteGuid(?string $guid): ?int
    {
        if (empty($guid)) return null;

        if (isset($this->requisiteMapCache[$guid])) {
            return $this->requisiteMapCache[$guid];
        }

        $response = $this->b24Service->call('crm.requisite.list', [
            'filter' => [self::REQUISITE_GUID_FIELD => $guid],
            'select' => ['ENTITY_ID']
        ]);

        $companyId = $response['result'][0]['ENTITY_ID'] ?? null;

        if ($companyId) {
            $this->requisiteMapCache[$guid] = (int)$companyId;
        }

        return $companyId ? (int)$companyId : null;
    }

    protected function findContactIdForCounterparty(string $counterpartyGuid): ?int
    {
        $contact = ContactPerson::where('counterparty_guid_1c', $counterpartyGuid)
            ->where('is_active', true)
            ->first();

        if (!$contact?->guid_1c) {
            return null;
        }

        $response = $this->b24Service->call('crm.contact.list', [
            'filter' => ['UF_CRM_GUID_1C' => $contact->guid_1c],
            'select' => ['ID'],
            'limit' => 1
        ]);

        return isset($response['result'][0]['ID']) ? (int)$response['result'][0]['ID'] : null;
    }

    protected function findUserIdByGuid(?string $guid): ?int
    {
        if (empty($guid)) return null;

        if ($this->usersCache === null) {
            $this->usersCache = [];
            $response = $this->b24Service->call('user.get', [
                'select' => ['ID', self::USER_GUID_FIELD],
                'filter' => ['ACTIVE' => 'Y']
            ]);

            foreach ($response['result'] ?? [] as $user) {
                if (!empty($user[self::USER_GUID_FIELD])) {
                    $this->usersCache[$user[self::USER_GUID_FIELD]] = (int)$user['ID'];
                }
            }
        }

        return $this->usersCache[$guid] ?? null;
    }

    protected function findProductIdByGuid(?string $guid): ?int
    {
        if (empty($guid)) return null;

        if (isset($this->productMapCache[$guid])) {
            return $this->productMapCache[$guid];
        }

        if ($this->productPropertiesCache === null) {
            $response = $this->b24Service->call('crm.product.property.list');
            $this->productPropertiesCache = [];
            foreach ($response['result'] ?? [] as $prop) {
                $this->productPropertiesCache[$prop['CODE']] = (int)$prop['ID'];
            }
        }

        $propId = $this->productPropertiesCache['GUID_1C'] ?? null;
        if (!$propId) return null;

        $response = $this->b24Service->call('crm.product.list', [
            'filter' => ['PROPERTY_' . $propId => $guid],
            'select' => ['ID'],
            'limit' => 1
        ]);

        $productId = $response['result'][0]['ID'] ?? null;

        if ($productId) {
            $this->productMapCache[$guid] = (int)$productId;
        }

        return $productId ? (int)$productId : null;
    }

    protected function getMeasureCode(?string $unitGuid): ?int
    {
        if (empty($unitGuid)) return null;

        $unit = UnitOfMeasure::where('guid_1c', $unitGuid)->first();

        return $unit?->code ? (int)$unit->code : null;
    }

    protected function cleanString(?string $value): ?string
    {
        if (empty($value)) return null;
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
