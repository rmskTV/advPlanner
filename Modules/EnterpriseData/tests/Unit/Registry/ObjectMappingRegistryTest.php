<?php

namespace Modules\EnterpriseData\Tests\Unit\Registry;

use Tests\TestCase;
use Modules\EnterpriseData\app\Registry\ObjectMappingRegistry;
use Modules\EnterpriseData\app\Contracts\ObjectMapping;
use Modules\EnterpriseData\app\ValueObjects\ValidationResult;
use Illuminate\Database\Eloquent\Model;

class ObjectMappingRegistryTest extends TestCase
{
    private ObjectMappingRegistry $registry;
    private ObjectMapping $mockMapping;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new ObjectMappingRegistry();
        $this->mockMapping = $this->createMockMapping();
    }

    public function test_can_register_and_retrieve_mapping(): void
    {
        $objectType = 'Справочник.Тест';

        $this->registry->registerMapping($objectType, $this->mockMapping);

        $retrievedMapping = $this->registry->getMapping($objectType);

        $this->assertSame($this->mockMapping, $retrievedMapping);
        $this->assertTrue($this->registry->hasMapping($objectType));
    }

    public function test_returns_null_for_unknown_mapping(): void
    {
        $mapping = $this->registry->getMapping('NonExistent.Type');

        $this->assertNull($mapping);
        $this->assertFalse($this->registry->hasMapping('NonExistent.Type'));
    }

    public function test_pattern_matching_works(): void
    {
        $this->registry->registerMapping('Справочник.*', $this->mockMapping);

        $this->assertTrue($this->registry->hasMapping('Справочник.Организации'));
        $this->assertTrue($this->registry->hasMapping('Справочник.Контрагенты'));
        $this->assertFalse($this->registry->hasMapping('Документ.ЗаказКлиента'));
    }

    public function test_identifies_priority_types_correctly(): void
    {
        $this->assertTrue($this->registry->isPriorityType('Справочник.Организации'));
        $this->assertTrue($this->registry->isPriorityType('Документ.ЗаказКлиента'));
        $this->assertFalse($this->registry->isPriorityType('Справочник.Неизвестный'));
    }

    public function test_returns_missing_priority_mappings(): void
    {
        $this->registry->registerMapping('Справочник.Организации', $this->mockMapping);

        $missing = $this->registry->getMissingPriorityMappings();

        $this->assertNotContains('Справочник.Организации', $missing);
        $this->assertContains('Справочник.Контрагенты', $missing);
    }

    public function test_mapping_statistics_calculation(): void
    {
        $this->registry->registerMapping('Справочник.Организации', $this->mockMapping);
        $this->registry->registerMapping('Справочник.*', $this->mockMapping);

        $stats = $this->registry->getMappingStatistics();

        $this->assertEquals(2, $stats['total_mappings']);
        $this->assertEquals(1, $stats['priority_mappings']);
        $this->assertEquals(1, $stats['pattern_mappings']);
        $this->assertEquals(1, $stats['exact_mappings']);
        $this->assertGreaterThan(0, $stats['priority_completion_rate']);
    }

    public function test_registry_validation(): void
    {
        $this->registry->registerMapping('Справочник.Организации', $this->mockMapping);

        $result = $this->registry->validateRegistry();

        $this->assertTrue($result->isValid());
        $this->assertNotEmpty($result->getWarnings()); // Должны быть предупреждения о недостающих маппингах
    }

    private function createMockMapping(): ObjectMapping
    {
        return new class extends ObjectMapping {
            public function getObjectType(): string
            {
                return 'Справочник.Тест';
            }

            public function getModelClass(): string
            {
                return Model::class;
            }

            public function mapFrom1C(array $object1C): Model
            {
                return new class extends Model {
                    protected $fillable = ['*'];
                };
            }

            public function mapTo1C(Model $laravelModel): array
            {
                return ['type' => 'Справочник.Тест'];
            }

            public function validateStructure(array $object1C): ValidationResult
            {
                return ValidationResult::success();
            }
        };
    }
}
