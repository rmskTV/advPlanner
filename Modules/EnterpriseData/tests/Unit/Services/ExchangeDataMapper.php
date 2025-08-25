<?php

namespace Modules\EnterpriseData\Tests\Unit\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\EnterpriseData\app\Contracts\ObjectMapping;
use Modules\EnterpriseData\app\Models\ExchangeFtpConnector;
use Modules\EnterpriseData\app\Registry\ObjectMappingRegistry;
use Modules\EnterpriseData\app\Services\ExchangeDataMapper;
use Modules\EnterpriseData\app\Services\ExchangeDataSanitizer;
use Modules\EnterpriseData\app\ValueObjects\ValidationResult;
use Tests\TestCase;

class ExchangeDataMapperTest extends TestCase
{
    use RefreshDatabase;

    private ExchangeDataMapper $mapper;

    private ObjectMappingRegistry $registry;

    private ExchangeDataSanitizer $sanitizer;

    private ExchangeFtpConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new ObjectMappingRegistry;
        $this->sanitizer = new ExchangeDataSanitizer;
        $this->mapper = new ExchangeDataMapper($this->registry, $this->sanitizer);

        $this->connector = new ExchangeFtpConnector;
        $this->connector->id = 1;
        $this->connector->foreign_base_name = 'Test Connector';
    }

    public function test_processes_mapped_objects_successfully(): void
    {
        $this->registry->registerMapping('Справочник.Тест', $this->createTestMapping());

        $objects1C = [
            [
                'type' => 'Справочник.Тест',
                'ref' => 'test-guid-1',
                'properties' => [
                    'КлючевыеСвойства' => [
                        'Ссылка' => 'test-guid-1',
                        'Наименование' => 'Test Object 1',
                    ],
                ],
                'tabular_sections' => [],
            ],
        ];

        $result = $this->mapper->processIncomingObjects($objects1C, $this->connector);

        $this->assertTrue($result->success);
        $this->assertEquals(1, $result->processedCount);
        $this->assertEmpty($result->errors);
    }

    public function test_skips_unmapped_objects(): void
    {
        $objects1C = [
            [
                'type' => 'Справочник.Неизвестный',
                'ref' => 'unknown-guid',
                'properties' => ['Наименование' => 'Unknown Object'],
                'tabular_sections' => [],
            ],
        ];

        $result = $this->mapper->processIncomingObjects($objects1C, $this->connector);

        $this->assertTrue($result->success);
        $this->assertEquals(0, $result->processedCount);
        $this->assertEmpty($result->errors);
    }

    public function test_handles_processing_errors_gracefully(): void
    {
        $failingMapping = $this->createFailingMapping();
        $this->registry->registerMapping('Справочник.Ошибочный', $failingMapping);

        $objects1C = [
            [
                'type' => 'Справочник.Ошибочный',
                'ref' => 'error-guid',
                'properties' => ['Наименование' => 'Error Object'],
                'tabular_sections' => [],
            ],
        ];

        $result = $this->mapper->processIncomingObjects($objects1C, $this->connector);

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->errors);
        $this->assertEquals(0, $result->processedCount);
    }

    public function test_groups_objects_by_type_correctly(): void
    {
        $testMapping = $this->createTestMapping();
        $this->registry->registerMapping('Справочник.Тест', $testMapping);

        $objects1C = [
            ['type' => 'Справочник.Тест', 'ref' => 'test-1', 'properties' => [], 'tabular_sections' => []],
            ['type' => 'Справочник.Тест', 'ref' => 'test-2', 'properties' => [], 'tabular_sections' => []],
            ['type' => 'Справочник.Неизвестный', 'ref' => 'unknown-1', 'properties' => [], 'tabular_sections' => []],
        ];

        $result = $this->mapper->processIncomingObjects($objects1C, $this->connector);

        $this->assertEquals(2, $result->processedCount); // Только маппированные объекты
    }

    public function test_processes_deletion_objects(): void
    {
        $objects1C = [
            [
                'type' => 'УдалениеОбъекта',
                'properties' => [
                    'СсылкаНаОбъект' => [
                        'СсылкаНаОбъект' => [
                            'ОрганизацияСсылка' => 'deleted-org-guid',
                        ],
                    ],
                ],
                'tabular_sections' => [],
            ],
        ];

        $result = $this->mapper->processIncomingObjects($objects1C, $this->connector);

        $this->assertTrue($result->success);
        $this->assertEquals(1, $result->processedCount);
    }

    private function createTestMapping(): ObjectMapping
    {
        return new class extends ObjectMapping
        {
            public function getObjectType(): string
            {
                return 'Справочник.Тест';
            }

            public function getModelClass(): string
            {
                return TestModel::class;
            }

            public function mapFrom1C(array $object1C): Model
            {
                $model = new TestModel;
                $model->guid_1c = $object1C['ref'] ?? null;
                $model->name = $object1C['properties']['КлючевыеСвойства']['Наименование'] ?? null;

                return $model;
            }

            public function mapTo1C(Model $laravelModel): array
            {
                return [
                    'type' => 'Справочник.Тест',
                    'ref' => $laravelModel->guid_1c,
                    'properties' => ['Наименование' => $laravelModel->name],
                ];
            }

            public function validateStructure(array $object1C): ValidationResult
            {
                return ValidationResult::success();
            }
        };
    }

    private function createFailingMapping(): ObjectMapping
    {
        return new class extends ObjectMapping
        {
            public function getObjectType(): string
            {
                return 'Справочник.Ошибочный';
            }

            public function getModelClass(): string
            {
                return TestModel::class;
            }

            public function mapFrom1C(array $object1C): Model
            {
                throw new \Exception('Mapping failed');
            }

            public function mapTo1C(Model $laravelModel): array
            {
                return [];
            }

            public function validateStructure(array $object1C): ValidationResult
            {
                return ValidationResult::success();
            }
        };
    }
}

class TestModel extends Model
{
    protected $fillable = ['guid_1c', 'name'];

    public $timestamps = false;

    protected $table = 'test_models';

    // Переопределяем методы для тестирования
    public function save(array $options = [])
    {
        return true;
    }

    public static function updateOrCreate(array $attributes, array $values = [])
    {
        $model = new static;
        $model->fill(array_merge($attributes, $values));
        $model->wasRecentlyCreated = true;

        return $model;
    }
}
