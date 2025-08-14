<?php

namespace Modules\EnterpriseData\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\EnterpriseData\app\Models\ExchangeFtpConnector;
use Modules\EnterpriseData\app\Registry\ObjectMappingRegistry;
use Modules\EnterpriseData\app\Services\ExchangeDataMapper;
use Modules\EnterpriseData\app\Services\ExchangeFileManager;
use Modules\EnterpriseData\app\Services\ExchangeMessageProcessor;

class InspectFileCommand extends Command
{
    protected $signature = 'exchange:inspect-file
                           {connector : ID коннектора}
                           {filename : Имя файла}
                           {--detailed : Детальный анализ XML структуры}
                           {--content : Показать полное содержимое файла}
                           {--debug-processing : Отладка процесса обработки объектов}
                           {--mappings : Показать информацию о маппингах}';

    protected $description = 'Инспекция файла обмена';

    public function handle(
        ExchangeFileManager $fileManager,
        ExchangeMessageProcessor $messageProcessor,
        ExchangeDataMapper $dataMapper,
        ObjectMappingRegistry $registry
    ): int {
        $connectorId = $this->argument('connector');
        $fileName = $this->argument('filename');

        $connector = ExchangeFtpConnector::find($connectorId);

        if (! $connector) {
            $this->error("Коннектор с ID {$connectorId} не найден");

            return self::FAILURE;
        }

        $this->info("Инспекция файла: {$fileName}");
        $this->line("Коннектор: {$connector->foreign_base_name}");

        try {
            // Базовая информация о файле
            $info = $fileManager->inspectFile($connector, $fileName);

            if (isset($info['error'])) {
                $this->error("Ошибка: {$info['error']}");

                return self::FAILURE;
            }

            $this->showBasicFileInfo($info);

            // Если запрошен детальный анализ
            if ($this->option('detailed')) {
                $this->showDetailedAnalysis($fileManager, $messageProcessor, $connector, $fileName);
            }

            // Если запрошена отладка обработки
            if ($this->option('debug-processing')) {
                $this->debugProcessing($fileManager, $messageProcessor, $dataMapper, $registry, $connector, $fileName);
            }

            // Если запрошена информация о маппингах
            if ($this->option('mappings')) {
                $this->showMappingsInfo($registry);
            }

            // Если запрошено полное содержимое
            if ($this->option('content')) {
                $this->showFullContent($fileManager, $connector, $fileName);
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Ошибка инспекции: {$e->getMessage()}");
            $this->line('Класс ошибки: '.get_class($e));

            if ($e->getPrevious()) {
                $this->line('Предыдущая ошибка: '.$e->getPrevious()->getMessage());
            }

            return self::FAILURE;
        }
    }

    private function showBasicFileInfo(array $info): void
    {
        $this->line('');
        $this->info('=== БАЗОВАЯ ИНФОРМАЦИЯ О ФАЙЛЕ ===');
        $this->line("Имя файла: {$info['file_name']}");
        $this->line("Путь: {$info['file_path']}");
        $this->line("Размер: {$info['size']} байт");
        $this->line("Последнее изменение: {$info['last_modified']}");
        $this->line("Кодировка: {$info['encoding']}");
        $this->line('Начинается с XML: '.($info['starts_with_xml'] ? 'Да' : 'Нет'));
        $this->line('Валидный XML: '.($info['is_valid_xml'] ? 'Да' : 'Нет'));
        $this->line('Входящий для нас: '.($info['is_incoming'] ? 'Да' : 'Нет'));
        $this->line('Исходящий от нас: '.($info['is_outgoing'] ? 'Да' : 'Нет'));
        $this->line('Заблокирован: '.($info['is_locked'] ? 'Да' : 'Нет'));

        $this->line('');
        $this->info('=== ПРЕВЬЮ СОДЕРЖИМОГО ===');
        $this->line($info['content_preview']);
    }

    private function showDetailedAnalysis(
        ExchangeFileManager $fileManager,
        ExchangeMessageProcessor $messageProcessor,
        ExchangeFtpConnector $connector,
        string $fileName
    ): void {
        $this->line('');
        $this->info('=== ДЕТАЛЬНЫЙ АНАЛИЗ XML ===');

        try {
            // Загружаем и парсим файл
            $content = $fileManager->downloadFile($connector, $fileName);

            // Показываем информацию о BOM
            $hasBOM = str_starts_with($content, "\xEF\xBB\xBF");
            $this->line('BOM символ: '.($hasBOM ? 'Найден (UTF-8)' : 'Отсутствует'));

            if ($hasBOM) {
                $this->line('Первые байты (hex): '.bin2hex(substr($content, 0, 10)));
            }

            // Парсим сообщение
            $parsedMessage = $messageProcessor->parseIncomingMessage($content);

            $this->line('');
            $this->info('=== ЗАГОЛОВОК СООБЩЕНИЯ ===');
            $this->line("Формат: {$parsedMessage->header->format}");
            $this->line("Дата создания: {$parsedMessage->header->creationDate->format('Y-m-d H:i:s')}");
            $this->line("План обмена: {$parsedMessage->header->exchangePlan}");
            $this->line("От узла: {$parsedMessage->header->fromNode}");
            $this->line("К узлу: {$parsedMessage->header->toNode}");
            $this->line("Номер сообщения: {$parsedMessage->header->messageNo}");
            $this->line("Получено сообщение №: {$parsedMessage->header->receivedNo}");
            $this->line('Доступные версии: '.implode(', ', $parsedMessage->header->availableVersions));

            if (! empty($parsedMessage->header->availableObjectTypes)) {
                $this->line('');
                $this->info('=== ДОСТУПНЫЕ ТИПЫ ОБЪЕКТОВ ===');
                $typeCount = 0;
                foreach ($parsedMessage->header->availableObjectTypes as $objectType) {
                    $this->line("- {$objectType['name']} (отправка: {$objectType['sending']}, получение: {$objectType['receiving']})");
                    $typeCount++;
                    if ($typeCount >= 10) {
                        $remaining = count($parsedMessage->header->availableObjectTypes) - 10;
                        if ($remaining > 0) {
                            $this->line("... и еще {$remaining} типов");
                        }
                        break;
                    }
                }
            }

            $this->line('');
            $this->info('=== ТЕЛО СООБЩЕНИЯ ===');
            $this->line("Количество объектов: {$parsedMessage->body->getObjectsCount()}");

            if (! $parsedMessage->body->isEmpty()) {
                $uniqueTypes = $parsedMessage->body->getUniqueObjectTypes();
                $this->line('Уникальные типы объектов: '.count($uniqueTypes));

                $this->line('');
                $this->info('=== ДЕТАЛИ ОБЪЕКТОВ ===');

                foreach (array_slice($uniqueTypes, 0, 10) as $type) {
                    $objectsOfType = $parsedMessage->body->getObjectsByType($type);
                    $this->line("📁 Тип '{$type}': ".count($objectsOfType).' объектов');

                    // Показываем первый объект типа
                    if (! empty($objectsOfType)) {
                        $firstObject = $objectsOfType[0];
                        $this->line('  └─ Пример объекта:');
                        $this->line('     Ref: '.($firstObject['ref'] ?? 'не указан'));
                        $this->line('     Свойств: '.count($firstObject['properties'] ?? []));
                        $this->line('     Табличных частей: '.count($firstObject['tabular_sections'] ?? []));

                        if (! empty($firstObject['properties'])) {
                            $keyProperties = array_slice(array_keys($firstObject['properties']), 0, 3);
                            $this->line('     Ключевые свойства: '.implode(', ', $keyProperties));
                        }
                    }
                }

                if (count($uniqueTypes) > 10) {
                    $this->line('... и еще '.(count($uniqueTypes) - 10).' типов');
                }
            } else {
                $this->warn('Тело сообщения пустое - объекты не найдены');
            }

        } catch (\Exception $e) {
            $this->error("Ошибка детального анализа: {$e->getMessage()}");
        }
    }

    private function showMappingsInfo(ObjectMappingRegistry $registry): void
    {
        $this->line('');
        $this->info('=== ИНФОРМАЦИЯ О МАППИНГАХ ===');

        $stats = $registry->getMappingStatistics();
        $this->line("Всего маппингов: {$stats['total_mappings']}");
        $this->line("Приоритетных маппингов: {$stats['priority_mappings']}");
        $this->line("Точных маппингов: {$stats['exact_mappings']}");
        $this->line("Паттерн маппингов: {$stats['pattern_mappings']}");
        $this->line("Покрытие приоритетных типов: {$stats['priority_completion_rate']}%");

        if (! empty($stats['missing_priority_types'])) {
            $this->line('');
            $this->warn('❌ ОТСУТСТВУЮЩИЕ ПРИОРИТЕТНЫЕ МАППИНГИ:');
            foreach ($stats['missing_priority_types'] as $type) {
                $this->line("  - {$type}");
            }
        }

        $mappings = $registry->getMappingsByCategory();

        if (! empty($mappings['exact_mappings'])) {
            $this->line('');
            $this->info('✅ ЗАРЕГИСТРИРОВАННЫЕ МАППИНГИ:');
            foreach ($mappings['exact_mappings'] as $type) {
                $isPriority = $registry->isPriorityType($type) ? '⭐' : '  ';
                $this->line("  {$isPriority} {$type}");
            }
        }

        if (! empty($mappings['pattern_mappings'])) {
            $this->line('');
            $this->info('🔍 ПАТТЕРН МАППИНГИ:');
            foreach ($mappings['pattern_mappings'] as $pattern) {
                $this->line("  - {$pattern}");
            }
        }
    }

    private function debugProcessing(
        ExchangeFileManager $fileManager,
        ExchangeMessageProcessor $messageProcessor,
        ExchangeDataMapper $dataMapper,
        ObjectMappingRegistry $registry,
        ExchangeFtpConnector $connector,
        string $fileName
    ): void {
        $this->line('');
        $this->info('=== ОТЛАДКА ПРОЦЕССА ОБРАБОТКИ ===');

        try {
            // Шаг 1: Загрузка и парсинг
            $this->line('1️⃣ Загрузка и парсинг файла...');
            $content = $fileManager->downloadFile($connector, $fileName);
            $parsedMessage = $messageProcessor->parseIncomingMessage($content);

            $totalObjects = count($parsedMessage->body->objects);
            $this->info("   ✓ Загружено и распарсено. Объектов: {$totalObjects}");

            // Шаг 2: Анализ маппингов
            $this->line('2️⃣ Анализ доступных маппингов...');

            $mappedObjects = [];
            $unmappedObjects = [];
            $objectTypeStats = [];

            foreach ($parsedMessage->body->objects as $object) {
                $objectType = $object['type'] ?? 'Unknown';

                if (! isset($objectTypeStats[$objectType])) {
                    $objectTypeStats[$objectType] = 0;
                }
                $objectTypeStats[$objectType]++;

                if ($registry->hasMapping($objectType)) {
                    $mappedObjects[] = $object;
                } else {
                    $unmappedObjects[] = $object;
                }
            }

            $this->info('   ✓ Объектов с маппингом: '.count($mappedObjects));
            $this->warn('   ⚠ Объектов без маппинга: '.count($unmappedObjects));

            // Шаг 3: Детали по типам
            $this->line('3️⃣ Анализ по типам объектов...');

            $mappedTypes = array_filter(array_keys($objectTypeStats), fn ($type) => $registry->hasMapping($type));
            $unmappedTypes = array_filter(array_keys($objectTypeStats), fn ($type) => ! $registry->hasMapping($type));

            if (! empty($mappedTypes)) {
                $this->info('   ✅ ТИПЫ С МАППИНГОМ:');
                foreach ($mappedTypes as $type) {
                    $count = $objectTypeStats[$type];
                    $isPriority = $registry->isPriorityType($type) ? '⭐' : '  ';
                    $this->line("     {$isPriority} {$type}: {$count} объектов");
                }
            }

            if (! empty($unmappedTypes)) {
                $this->warn('   ❌ ТИПЫ БЕЗ МАППИНГА:');
                foreach (array_slice($unmappedTypes, 0, 10) as $type) {
                    $count = $objectTypeStats[$type];
                    $isPriority = $registry->isPriorityType($type) ? '⭐' : '  ';
                    $this->line("     {$isPriority} {$type}: {$count} объектов");
                }
                if (count($unmappedTypes) > 10) {
                    $this->line('     ... и еще '.(count($unmappedTypes) - 10).' типов');
                }
            }

            // Шаг 4: Симуляция обработки маппированных объектов
            if (! empty($mappedObjects)) {
                $this->line('4️⃣ Симуляция обработки маппированных объектов...');

                try {
                    // Создаем временный массив только с маппированными объектами
                    $result = $dataMapper->processIncomingObjects($mappedObjects, $connector);

                    $this->info('   ✓ Результат симуляции:');
                    $this->line('     Успех: '.($result->success ? 'Да' : 'Нет'));
                    $this->line("     Обработано: {$result->processedCount}");
                    $this->line('     Создано: '.count($result->createdIds));
                    $this->line('     Обновлено: '.count($result->updatedIds));
                    $this->line('     Удалено: '.count($result->deletedIds));

                    if (! empty($result->errors)) {
                        $this->error('     Ошибок: '.count($result->errors));
                        foreach (array_slice($result->errors, 0, 3) as $error) {
                            $this->line("       - {$error}");
                        }
                    }

                } catch (\Exception $e) {
                    $this->error("   ✗ Ошибка симуляции: {$e->getMessage()}");
                }
            }

        } catch (\Exception $e) {
            $this->error("Ошибка отладки: {$e->getMessage()}");
        }
    }

    private function showFullContent(
        ExchangeFileManager $fileManager,
        ExchangeFtpConnector $connector,
        string $fileName
    ): void {
        try {
            $content = $fileManager->downloadFile($connector, $fileName);

            $this->line('');
            $this->info('=== ПОЛНОЕ СОДЕРЖИМОЕ ФАЙЛА ===');
            $this->line($content);

        } catch (\Exception $e) {
            $this->error("Не удалось загрузить содержимое: {$e->getMessage()}");
        }
    }
}
