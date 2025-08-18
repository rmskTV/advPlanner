<?php

namespace Modules\EnterpriseData\Tests\Unit\Mappings;

use Tests\TestCase;
use Modules\EnterpriseData\app\Mappings\OrganizationMapping;
use Modules\Accounting\app\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OrganizationMappingTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationMapping $mapping;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapping = new OrganizationMapping();
    }

    public function test_returns_correct_object_type(): void
    {
        $this->assertEquals('Справочник.Организации', $this->mapping->getObjectType());
    }

    public function test_returns_correct_model_class(): void
    {
        $this->assertEquals(Organization::class, $this->mapping->getModelClass());
    }

    public function test_maps_from_1c_successfully(): void
    {
        $object1C = [
            'ref' => 'test-org-guid',
            'type' => 'Справочник.Организации',
            'properties' => [
                'КлючевыеСвойства' => [
                    'Ссылка' => 'test-org-guid',
                    'Наименование' => 'ООО Тестовая организация',
                    'НаименованиеПолное' => 'Общество с ограниченной ответственностью "Тестовая организация"',
                    'ИНН' => '1234567890',
                    'КПП' => '123456789'
                ],
                'Префикс' => 'ТО',
                'ОКПО' => '12345678',
                'ОГРН' => '1234567890123'
            ],
            'tabular_sections' => []
        ];

        $organization = $this->mapping->mapFrom1C($object1C);

        $this->assertInstanceOf(Organization::class, $organization);
        $this->assertEquals('test-org-guid', $organization->guid_1c);
        $this->assertEquals('ООО Тестовая организация', $organization->name);
        $this->assertEquals('1234567890', $organization->inn);
        $this->assertEquals('123456789', $organization->kpp);
        $this->assertEquals('ТО', $organization->prefix);
        $this->assertFalse($organization->deletion_mark);
        $this->assertNotNull($organization->last_sync_at);
    }

    public function test_maps_to_1c_successfully(): void
    {
        $organization = new Organization([
            'guid_1c' => 'test-org-guid',
            'name' => 'ООО Тестовая организация',
            'full_name' => 'Общество с ограниченной ответственностью "Тестовая организация"',
            'inn' => '1234567890',
            'kpp' => '123456789',
            'prefix' => 'ТО'
        ]);

        $result = $this->mapping->mapTo1C($organization);

        $this->assertEquals('Справочник.Организации', $result['type']);
        $this->assertEquals('test-org-guid', $result['ref']);
        $this->assertEquals('ООО Тестовая организация', $result['properties']['КлючевыеСвойства']['Наименование']);
        $this->assertEquals('1234567890', $result['properties']['КлючевыеСвойства']['ИНН']);
    }

    public function test_validates_structure_successfully(): void
    {
        $validObject = [
            'properties' => [
                'КлючевыеСвойства' => [
                    'Наименование' => 'Test Organization'
                ]
            ]
        ];

        $result = $this->mapping->validateStructure($validObject);

        $this->assertTrue($result->isValid());
    }

    public function test_validates_structure_with_errors(): void
    {
        $invalidObject = [
            'properties' => []
        ];

        $result = $this->mapping->validateStructure($invalidObject);

        $this->assertFalse($result->isValid());
        $this->assertContains('КлючевыеСвойства section is missing', $result->getErrors());
    }

    public function test_validates_structure_with_empty_name(): void
    {
        $invalidObject = [
            'properties' => [
                'КлючевыеСвойства' => [
                    'Наименование' => ''
                ]
            ]
        ];

        $result = $this->mapping->validateStructure($invalidObject);

        $this->assertFalse($result->isValid());
        $this->assertContains('Organization name is required in КлючевыеСвойства.Наименование', $result->getErrors());
    }

    public function test_validates_inn_format(): void
    {
        $objectWithInvalidInn = [
            'properties' => [
                'КлючевыеСвойства' => [
                    'Наименование' => 'Test Organization',
                    'ИНН' => '123' // Неверный формат ИНН
                ]
            ]
        ];

        $result = $this->mapping->validateStructure($objectWithInvalidInn);

        $this->assertTrue($result->isValid()); // Структура валидна
        $this->assertTrue($result->hasWarnings()); // Но есть предупреждения
        $this->assertStringContains('Invalid INN format', implode(' ', $result->getWarnings()));
    }

    public function test_handles_long_organization_name(): void
    {
        $longName = str_repeat('A', 300); // Имя длиннее 255 символов

        $object1C = [
            'properties' => [
                'КлючевыеСвойства' => [
                    'Наименование' => $longName
                ]
            ]
        ];

        $result = $this->mapping->validateStructure($object1C);

        $this->assertFalse($result->isValid());
        $this->assertContains('Organization name is too long (max 255 characters)', $result->getErrors());
    }

    public function test_sanitizes_string_values(): void
    {
        $object1C = [
            'properties' => [
                'КлючевыеСвойства' => [
                    'Наименование' => 'ООО "Тест"   ', // С пробелами
                    'ИНН' => '  1234567890  ' // С пробелами
                ]
            ]
        ];

        $organization = $this->mapping->mapFrom1C($object1C);

        $this->assertEquals('ООО "Тест"', $organization->name); // Пробелы убраны
        $this->assertEquals('1234567890', $organization->inn);
    }
}
