<?php

namespace Modules\EnterpriseData\app\Mappings;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\CustomerOrder;
use Modules\Accounting\app\Models\OrderPaymentStatus;
use Modules\EnterpriseData\app\Contracts\ObjectMapping;
use Modules\EnterpriseData\app\ValueObjects\ValidationResult;

class OrderPaymentStatusMapping extends ObjectMapping
{
    public function getObjectType(): string
    {
        return 'Справочник.СостояниеОплатыЗаказа';
    }

    public function getModelClass(): string
    {
        return OrderPaymentStatus::class;
    }

    public function mapFrom1C(array $object1C): Model
    {
        $properties = $object1C['properties'] ?? [];
        $keyProperties = $properties['КлючевыеСвойства'] ?? [];

        $status = new OrderPaymentStatus;

        // Данные заказа
        $orderData = $keyProperties['Заказ'] ?? [];
        if (! empty($orderData)) {
            $status->order_guid_1c = $orderData['Ссылка'] ?? null;
            $status->order_number = $orderData['Номер'] ?? null;

            $orderDateString = $orderData['Дата'] ?? null;
            if ($orderDateString) {
                try {
                    $status->order_date = Carbon::parse($orderDateString);
                } catch (\Exception $e) {
                    $status->order_date = null;
                }
            }

            $organizationData = $orderData['Организация'] ?? [];
            $status->organization_guid_1c = $organizationData['Ссылка'] ?? null;

            // Ищем заказ в нашей базе
            if ($status->order_guid_1c) {
                $customerOrder = CustomerOrder::findByGuid1C($status->order_guid_1c);
                $status->customer_order_id = $customerOrder?->id;
            }
        }

        // Состояние оплаты
        $status->payment_status = $this->getFieldValue($properties, 'СостояниеОплаты', 'Неизвестно');
        $status->last_sync_at = now();

        Log::info('Mapped OrderPaymentStatus successfully', [
            'order_guid' => $status->order_guid_1c,
            'payment_status' => $status->payment_status,
            'customer_order_id' => $status->customer_order_id,
        ]);

        return $status;
    }

    public function mapTo1C(Model $laravelModel): array
    {
        /** @var OrderPaymentStatus $laravelModel */
        return [
            'type' => 'Справочник.СостояниеОплатыЗаказа',
            'ref' => null,
            'properties' => [
                'КлючевыеСвойства' => [
                    'Заказ' => [
                        'Ссылка' => $laravelModel->order_guid_1c,
                        'Номер' => $laravelModel->order_number,
                        'Дата' => $laravelModel->order_date?->format('Y-m-d\TH:i:s'),
                    ],
                ],
                'СостояниеОплаты' => $laravelModel->payment_status,
            ],
            'tabular_sections' => [],
        ];
    }

    public function validateStructure(array $object1C): ValidationResult
    {
        $properties = $object1C['properties'] ?? [];
        $keyProperties = $properties['КлючевыеСвойства'] ?? [];
        $warnings = [];

        if (empty($keyProperties)) {
            return ValidationResult::failure(['КлючевыеСвойства section is missing']);
        }

        $orderData = $keyProperties['Заказ'] ?? [];
        if (empty($orderData) || empty($orderData['Ссылка'])) {
            $warnings[] = 'Order reference is missing';
        }

        $paymentStatus = $this->getFieldValue($properties, 'СостояниеОплаты');
        if (empty($paymentStatus)) {
            $warnings[] = 'Payment status is missing';
        }

        return ValidationResult::success($warnings);
    }
}
