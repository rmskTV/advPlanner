<?php

namespace Modules\EnterpriseData\app\Mappings;

use App\Models\Catalog;
use Illuminate\Database\Eloquent\Model;
use Modules\EnterpriseData\app\Contracts\ObjectMapping;
use Modules\EnterpriseData\app\ValueObjects\ValidationResult;

class CatalogMapping extends ObjectMapping
{
    public function getObjectType(): string
    {
        return 'Справочник.*';
    }

    public function getModelClass(): string
    {
        return Catalog::class;
    }

    public function mapFrom1C(array $object1C): Model
    {
        $catalog = new Catalog;

        $catalog->guid_1c = $this->getFieldValue($object1C, 'ref');
        $catalog->name = $this->getFieldValue($object1C, 'properties.Наименование', '');
        $catalog->code = $this->getFieldValue($object1C, 'properties.Код', '');
        $catalog->description = $this->getFieldValue($object1C, 'properties.Комментарий', '');
        $catalog->is_folder = $this->getFieldValue($object1C, 'properties.ЭтоГруппа', false);
        $catalog->parent_guid_1c = $this->getFieldValue($object1C, 'properties.Родитель');
        $catalog->deletion_mark = $this->getFieldValue($object1C, 'properties.ПометкаУдаления', false);

        return $catalog;
    }

    public function mapTo1C(Model $laravelModel): array
    {
        /** @var Catalog $laravelModel */
        return [
            'type' => 'Справочник.Номенклатура',
            'ref' => $laravelModel->guid_1c,
            'properties' => [
                'Наименование' => $laravelModel->name,
                'Код' => $laravelModel->code,
                'Комментарий' => $laravelModel->description,
                'ЭтоГруппа' => $laravelModel->is_folder,
                'Родитель' => $laravelModel->parent_guid_1c,
                'ПометкаУдаления' => $laravelModel->deletion_mark,
            ],
            'tabular_sections' => [],
        ];
    }

    public function validateStructure(array $object1C): ValidationResult
    {
        $requiredFields = [
            'ref',
            'type',
            'properties.Наименование',
        ];

        $validation = $this->validateRequiredFields($object1C, $requiredFields);

        if (! $validation->isValid()) {
            return $validation;
        }

        // Дополнительная валидация
        $errors = [];

        $name = $this->getFieldValue($object1C, 'properties.Наименование');
        if (empty(trim($name))) {
            $errors[] = 'Name cannot be empty';
        }

        if (strlen($name) > 150) {
            $errors[] = 'Name is too long (max 150 characters)';
        }

        return new ValidationResult(empty($errors), $errors);
    }
}
