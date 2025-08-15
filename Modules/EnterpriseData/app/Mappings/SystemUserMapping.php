<?php

namespace Modules\EnterpriseData\app\Mappings;

use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\app\Models\SystemUser;
use Modules\EnterpriseData\app\Contracts\ObjectMapping;
use Modules\EnterpriseData\app\ValueObjects\ValidationResult;

class SystemUserMapping extends ObjectMapping
{
    public function getObjectType(): string
    {
        return 'Справочник.Пользователи';
    }

    public function getModelClass(): string
    {
        return SystemUser::class;
    }

    public function mapFrom1C(array $object1C): Model
    {
        $properties = $object1C['properties'] ?? [];
        $keyProperties = $properties['КлючевыеСвойства'] ?? [];

        $user = new SystemUser;

        // Основные реквизиты из ключевых свойств
        $user->guid_1c = $this->getFieldValue($keyProperties, 'Ссылка') ?: ($object1C['ref'] ?? null);
        $user->name = $this->getFieldValue($keyProperties, 'Наименование');

        // Логин обычно совпадает с наименованием или извлекается из него
        $user->login = $user->name;

        // Дополнительные свойства
        $user->description = $this->getFieldValue($properties, 'Комментарий') ?:
            $this->getFieldValue($properties, 'Описание');

        // Системные поля
        $user->is_active = true;
        $user->deletion_mark = false;
        $user->last_sync_at = now();

        return $user;
    }

    public function mapTo1C(Model $laravelModel): array
    {
        /** @var SystemUser $laravelModel */
        return [
            'type' => 'Справочник.Пользователи',
            'ref' => $laravelModel->guid_1c,
            'properties' => [
                'КлючевыеСвойства' => [
                    'Ссылка' => $laravelModel->guid_1c,
                    'Наименование' => $laravelModel->name,
                ],
            ],
            'tabular_sections' => [],
        ];
    }

    public function validateStructure(array $object1C): ValidationResult
    {
        $properties = $object1C['properties'] ?? [];
        $keyProperties = $properties['КлючевыеСвойства'] ?? [];
        $warnings = [];

        // Проверяем наличие ключевых свойств
        if (empty($keyProperties)) {
            return ValidationResult::failure(['КлючевыеСвойства section is missing']);
        }

        // Проверяем наименование
        $name = $this->getFieldValue($keyProperties, 'Наименование');
        if (empty(trim($name))) {
            $warnings[] = 'User name is missing';
        }

        return ValidationResult::success($warnings);
    }
}
