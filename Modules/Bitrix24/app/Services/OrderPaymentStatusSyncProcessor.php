<?php

namespace Modules\Bitrix24\app\Services;

use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\ObjectChangeLog;
use Modules\Accounting\app\Models\OrderPaymentStatus;

class OrderPaymentStatusSyncProcessor
{
    protected Bitrix24Service $b24Service;

    const INVOICE_ENTITY_TYPE_ID = 31;

    // Маппинг статусов 1С → ID значений в B24
    const PAYMENT_STATUS_MAP = [
        'НеОплачен' => '45',
        'Оплачен' => '47',
        'ОплаченЧастично' => '49',
    ];

    public function __construct(Bitrix24Service $b24Service)
    {
        $this->b24Service = $b24Service;
    }

    /**
     * Обработка изменения статуса оплаты
     */
    public function processPaymentStatus(ObjectChangeLog $change): void
    {
        $paymentStatus = OrderPaymentStatus::find($change->local_id);

        if (!$paymentStatus) {
            throw new \Exception("OrderPaymentStatus not found: {$change->local_id}");
        }

        if (empty($paymentStatus->order_guid_1c)) {
            $change->status = 'skipped';
            $change->error = 'Missing order_guid_1c';
            $change->save();
            return;
        }

        Log::info("Processing OrderPaymentStatus", [
            'id' => $paymentStatus->id,
            'order_guid' => $paymentStatus->order_guid_1c,
            'payment_status' => $paymentStatus->payment_status,
            'order_number' => $paymentStatus->order_number
        ]);

        // 1.а Находим счёт в B24 по xmlId (GUID заказа)
        $b24InvoiceId = $this->findInvoiceByGuid($paymentStatus->order_guid_1c);

        if (!$b24InvoiceId) {
            // Счёт ещё не синхронизирован в B24 — это нормально
            Log::info("Invoice not found in B24 yet", [
                'order_guid' => $paymentStatus->order_guid_1c,
                'order_number' => $paymentStatus->order_number
            ]);

            $change->status = 'error';
            $change->error = 'Invoice not synced to B24 yet';
            $change->save();
            return;
        }

        // 2. Маппим статус на ID значения
        $statusValueId = $this->mapPaymentStatus($paymentStatus->payment_status);

        if (!$statusValueId) {
            Log::warning("Unknown payment status", [
                'status' => $paymentStatus->payment_status,
                'order_number' => $paymentStatus->order_number
            ]);

            $change->status = 'skipped';
            $change->error = "Unknown payment status: {$paymentStatus->payment_status}";
            $change->save();
            return;
        }

        // 3. Обновляем статус в B24
        try {
            $result = $this->b24Service->call('crm.item.update', [
                'entityTypeId' => self::INVOICE_ENTITY_TYPE_ID,
                'id' => $b24InvoiceId,
                'fields' => [
                    'ufCrm_SMART_INVOICE_PAYMENT_STATUS_1C' => $statusValueId
                ]
            ]);

            if (empty($result['result'])) {
                throw new \Exception("Failed to update invoice: " . json_encode($result));
            }

            $change->b24_id = $b24InvoiceId;
            $change->markProcessed();

            Log::info("Payment status synced successfully", [
                'invoice_id' => $b24InvoiceId,
                'status_1c' => $paymentStatus->payment_status,
                'status_b24_id' => $statusValueId
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to update payment status in B24: " . $e->getMessage(), [
                'invoice_id' => $b24InvoiceId,
                'status' => $paymentStatus->payment_status
            ]);
            throw $e;
        }
    }

    /**
     * Поиск счёта в B24 по GUID заказа
     */
    protected function findInvoiceByGuid(string $orderGuid): ?int
    {
        $response = $this->b24Service->call('crm.item.list', [
            'entityTypeId' => self::INVOICE_ENTITY_TYPE_ID,
            'filter' => ['xmlId' => $orderGuid],
            'select' => ['id'],
            'limit' => 1,
        ]);

        return $response['result']['items'][0]['id'] ?? null;
    }

    /**
     * Маппинг статуса 1С → ID значения в B24
     */
    protected function mapPaymentStatus(string $status1c): ?string
    {
        return self::PAYMENT_STATUS_MAP[$status1c] ?? null;
    }
}
