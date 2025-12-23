<?php
// Modules/Bitrix24/app/Services/Processors/OrderPaymentStatusSyncProcessor.php

namespace Modules\Bitrix24\app\Services\Processors;

use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\ObjectChangeLog;
use Modules\Accounting\app\Models\OrderPaymentStatus;
use Modules\Bitrix24\app\Exceptions\DependencyNotReadyException;
use Modules\Bitrix24\app\Exceptions\ValidationException;

class OrderPaymentStatusSyncProcessor extends AbstractBitrix24Processor
{
    const INVOICE_ENTITY_TYPE_ID = 31;

    // Маппинг статусов 1С → ID значений списка в B24
    const PAYMENT_STATUS_MAP = [
        'НеОплачен' => '45',
        'Оплачен' => '47',
        'ОплаченЧастично' => '49',
    ];

    protected function syncEntity(ObjectChangeLog $change): void
    {
        $paymentStatus = OrderPaymentStatus::find($change->local_id);

        if (!$paymentStatus) {
            throw new ValidationException("OrderPaymentStatus not found: {$change->local_id}");
        }

        // Валидация
        $this->validatePaymentStatus($paymentStatus);

        Log::info("Processing payment status", [
            'order_guid' => $paymentStatus->order_guid_1c,
            'status' => $paymentStatus->payment_status,
            'order_number' => $paymentStatus->order_number
        ]);

        // Находим счёт в B24
        $invoiceId = $this->findInvoiceByGuid($paymentStatus->order_guid_1c);

        if (!$invoiceId) {
            throw new DependencyNotReadyException(
                "Invoice not synced yet for order GUID: {$paymentStatus->order_guid_1c}"
            );
        }

        // Маппим статус
        $statusValueId = $this->mapPaymentStatus($paymentStatus->payment_status);

        if (!$statusValueId) {
            throw new ValidationException(
                "Unknown payment status: {$paymentStatus->payment_status}"
            );
        }

        // Обновляем статус в B24
        $this->updateInvoicePaymentStatus($invoiceId, $statusValueId);

        $change->b24_id = $invoiceId;

        Log::info("Payment status synced", [
            'invoice_id' => $invoiceId,
            'status_1c' => $paymentStatus->payment_status,
            'status_b24_id' => $statusValueId
        ]);
    }

    /**
     * Валидация статуса оплаты
     */
    protected function validatePaymentStatus(OrderPaymentStatus $paymentStatus): void
    {
        if (empty($paymentStatus->order_guid_1c)) {
            throw new ValidationException("PaymentStatus {$paymentStatus->id} has no order_guid_1c");
        }
    }

    /**
     * Обновление статуса оплаты счёта
     */
    protected function updateInvoicePaymentStatus(int $invoiceId, string $statusValueId): void
    {
        $result = $this->b24Service->call('crm.item.update', [
            'entityTypeId' => self::INVOICE_ENTITY_TYPE_ID,
            'id' => $invoiceId,
            'fields' => [
                'ufCrm_SMART_INVOICE_PAYMENT_STATUS_1C' => $statusValueId
            ]
        ]);

        if (empty($result['result'])) {
            throw new \Exception("Failed to update invoice payment status: " . json_encode($result));
        }
    }

    /**
     * Поиск счёта по GUID заказа
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
