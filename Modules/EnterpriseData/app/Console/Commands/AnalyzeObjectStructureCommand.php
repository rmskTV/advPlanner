<?php

namespace Modules\EnterpriseData\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\EnterpriseData\app\Models\ExchangeFtpConnector;
use Modules\EnterpriseData\app\Services\ExchangeFileManager;
use Modules\EnterpriseData\app\Services\ExchangeMessageProcessor;

class AnalyzeObjectStructureCommand extends Command
{
    protected $signature = 'exchange:analyze-object
                           {connector : ID коннектора}
                           {filename : Имя файла}
                           {object-type : Тип объекта для анализа}
                           {--index=0 : Индекс объекта (по умолчанию первый)}';

    protected $description = 'Детальный анализ структуры конкретного объекта';

    public function handle(
        ExchangeFileManager $fileManager,
        ExchangeMessageProcessor $messageProcessor
    ): int {
        $connectorId = $this->argument('connector');
        $fileName = $this->argument('filename');
        $objectType = $this->argument('object-type');
        $index = $this->option('index');

        $connector = ExchangeFtpConnector::find($connectorId);

        if (! $connector) {
            $this->error("Коннектор с ID {$connectorId} не найден");

            return self::FAILURE;
        }

        try {
            // Загружаем и парсим файл
            $content = $fileManager->downloadFile($connector, $fileName);
            $parsedMessage = $messageProcessor->parseIncomingMessage($content);

            // Показываем все доступные типы
            $allTypes = $parsedMessage->body->getUniqueObjectTypes();
            $this->info('Доступные типы объектов в файле:');
            foreach ($allTypes as $type) {
                $count = count($parsedMessage->body->getObjectsByType($type));
                $this->line("  - {$type}: {$count} объектов");
            }

            // Ищем объекты нужного типа
            $objectsOfType = $parsedMessage->body->getObjectsByType($objectType);

            if (empty($objectsOfType)) {
                $this->error("Объекты типа '{$objectType}' не найдены в файле");

                // Показываем похожие типы
                $similarTypes = array_filter($allTypes, function ($type) use ($objectType) {
                    return str_contains($type, 'Договор') || str_contains($objectType, 'Договор');
                });

                if (! empty($similarTypes)) {
                    $this->line('Возможно, вы имели в виду один из этих типов:');
                    foreach ($similarTypes as $type) {
                        $count = count($parsedMessage->body->getObjectsByType($type));
                        $this->line("  - {$type}: {$count} объектов");
                    }
                }

                return self::FAILURE;
            }

            if ($index >= count($objectsOfType)) {
                $this->error("Индекс {$index} превышает количество объектов типа '{$objectType}' (".count($objectsOfType).')');

                return self::FAILURE;
            }

            $object = $objectsOfType[$index];

            $this->info("=== АНАЛИЗ ОБЪЕКТА '{$objectType}' (#{$index}) ===");
            $this->line('Ref: '.($object['ref'] ?? 'не указан'));
            $this->line('Тип: '.($object['type'] ?? 'не указан'));
            $this->line('Свойств: '.count($object['properties'] ?? []));
            $this->line('Табличных частей: '.count($object['tabular_sections'] ?? []));

            // Детальный анализ свойств
            if (! empty($object['properties'])) {
                $this->line('');
                $this->info('=== СВОЙСТВА ===');

                foreach ($object['properties'] as $propName => $propValue) {
                    $valueType = gettype($propValue);
                    $valuePreview = $this->getValuePreview($propValue);

                    $this->line("📋 {$propName} ({$valueType}): {$valuePreview}");

                    // Если это массив, показываем его структуру
                    if (is_array($propValue) && ! empty($propValue)) {
                        $this->line('   └─ Ключи массива: '.implode(', ', array_keys($propValue)));
                    }
                }
            }

            // Детальный анализ табличных частей
            if (! empty($object['tabular_sections'])) {
                $this->line('');
                $this->info('=== ТАБЛИЧНЫЕ ЧАСТИ ===');

                foreach ($object['tabular_sections'] as $sectionName => $rows) {
                    $this->line("📊 {$sectionName}: ".count($rows).' строк');

                    if (! empty($rows)) {
                        $firstRow = $rows[0];
                        if (is_array($firstRow)) {
                            $this->line('   └─ Колонки: '.implode(', ', array_keys($firstRow)));
                        }
                    }
                }
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Ошибка анализа: {$e->getMessage()}");
            $this->line('Trace: '.$e->getTraceAsString());

            return self::FAILURE;
        }
    }

    private function getValuePreview($value): string
    {
        if (is_null($value)) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return 'array('.count($value).' элементов)';
        }

        if (is_string($value)) {
            return strlen($value) > 50 ? '"'.substr($value, 0, 50).'..."' : '"'.$value.'"';
        }

        return (string) $value;
    }
}
