<?php

namespace Modules\EnterpriseData\app\Registry;

use Modules\EnterpriseData\app\Contracts\ObjectMapping;
use Modules\EnterpriseData\app\Exceptions\ExchangeMappingException;
use Modules\EnterpriseData\app\ValueObjects\ValidationResult;

class ObjectMappingRegistry
{
    private array $mappings = [];

    /**
     * Приоритетные типы объектов, которые ДОЛЖНЫ иметь маппинг
     */
    private array $priorityTypes = [
        'Справочник.Организации',
        'Справочник.Договоры',
        'Справочник.Контрагенты',
        'Справочник.КонтрагентыГруппа',
        // 'Справочник.ФизическиеЛица',
        'Справочник.Валюты',
        'Справочник.Пользователи',
        'Справочник.ЕдиницыИзмерения',
        'Справочник.Номенклатура',
        'Справочник.НоменклатураГруппа',
        'Документ.РеализацияТоваровУслуг',
        'Документ.ЗаказКлиента',
        'УдалениеОбъекта',
    ];

    /**
     * Регистрация маппинга для типа объекта
     */
    public function registerMapping(string $objectType, ObjectMapping $mapping): void
    {
        $this->mappings[$objectType] = $mapping;
    }

    /**
     * Получение маппинга для типа объекта
     */
    public function getMapping(string $objectType): ?ObjectMapping
    {
        // Точное совпадение
        if (isset($this->mappings[$objectType])) {
            return $this->mappings[$objectType];
        }

        // Поиск по паттерну (например, "Справочник.*" для всех справочников)
        foreach ($this->mappings as $pattern => $mapping) {
            if (fnmatch($pattern, $objectType)) {
                return $mapping;
            }
        }

        return null;
    }

    /**
     * Проверка наличия маппинга
     */
    public function hasMapping(string $objectType): bool
    {
        return $this->getMapping($objectType) !== null;
    }

    /**
     * Проверка, является ли тип объекта приоритетным
     */
    public function isPriorityType(string $objectType): bool
    {
        return in_array($objectType, $this->priorityTypes);
    }

    /**
     * Получение списка приоритетных типов
     */
    public function getPriorityTypes(): array
    {
        return $this->priorityTypes;
    }

    /**
     * Получение приоритетных типов без маппинга
     */
    public function getMissingPriorityMappings(): array
    {
        $missing = [];

        foreach ($this->priorityTypes as $objectType) {
            if (! $this->hasMapping($objectType)) {
                $missing[] = $objectType;
            }
        }

        return $missing;
    }

    /**
     * Получение всех зарегистрированных маппингов
     */
    public function getAllMappings(): array
    {
        return $this->mappings;
    }

    /**
     * Получение списка поддерживаемых типов объектов
     */
    public function getSupportedObjectTypes(): array
    {
        return array_keys($this->mappings);
    }

    /**
     * Удаление маппинга
     */
    public function unregisterMapping(string $objectType): void
    {
        unset($this->mappings[$objectType]);
    }

    /**
     * Получение статистики маппингов
     */
    public function getMappingStatistics(): array
    {
        $totalMappings = count($this->mappings);
        $priorityMappings = 0;
        $patternMappings = 0;
        $exactMappings = 0;

        foreach ($this->mappings as $pattern => $mapping) {
            if (in_array($pattern, $this->priorityTypes)) {
                $priorityMappings++;
            }

            if (str_contains($pattern, '*') || str_contains($pattern, '?')) {
                $patternMappings++;
            } else {
                $exactMappings++;
            }
        }

        $missingPriority = $this->getMissingPriorityMappings();

        return [
            'total_mappings' => $totalMappings,
            'priority_mappings' => $priorityMappings,
            'pattern_mappings' => $patternMappings,
            'exact_mappings' => $exactMappings,
            'missing_priority_mappings' => count($missingPriority),
            'missing_priority_types' => $missingPriority,
            'priority_completion_rate' => count($this->priorityTypes) > 0
                ? round((($priorityMappings / count($this->priorityTypes)) * 100), 2)
                : 0,
        ];
    }

    /**
     * Валидация реестра маппингов
     */
    public function validateRegistry(): ValidationResult
    {
        $errors = [];
        $warnings = [];

        // Проверка дублирующихся маппингов
        $objectTypes = [];
        foreach ($this->mappings as $pattern => $mapping) {
            $mappingObjectType = $mapping->getObjectType();
            if (isset($objectTypes[$mappingObjectType])) {
                $errors[] = "Duplicate mapping for object type: {$mappingObjectType}";
            }
            $objectTypes[$mappingObjectType] = $pattern;
        }

        // Проверка отсутствующих приоритетных маппингов
        $missingPriority = $this->getMissingPriorityMappings();
        if (! empty($missingPriority)) {
            $warnings[] = 'Missing priority mappings: '.implode(', ', $missingPriority);
        }

        // Проверка валидности каждого маппинга
        foreach ($this->mappings as $pattern => $mapping) {
            try {
                $modelClass = $mapping->getModelClass();
                if (! class_exists($modelClass)) {
                    $errors[] = "Model class does not exist for {$pattern}: {$modelClass}";
                }
            } catch (\Exception $e) {
                $errors[] = "Invalid mapping for {$pattern}: ".$e->getMessage();
            }
        }

        return empty($errors)
            ? ValidationResult::success($warnings)
            : ValidationResult::failure($errors, $warnings);
    }

    /**
     * Получение маппингов по типу (точные, паттерны, приоритетные)
     */
    public function getMappingsByCategory(): array
    {
        $exact = [];
        $patterns = [];
        $priority = [];
        $missing = [];

        foreach ($this->priorityTypes as $priorityType) {
            if ($this->hasMapping($priorityType)) {
                $priority[] = $priorityType;
            } else {
                $missing[] = $priorityType;
            }
        }

        foreach ($this->mappings as $pattern => $mapping) {
            if (str_contains($pattern, '*') || str_contains($pattern, '?')) {
                $patterns[] = $pattern;
            } else {
                $exact[] = $pattern;
            }
        }

        return [
            'exact_mappings' => $exact,
            'pattern_mappings' => $patterns,
            'priority_mappings' => $priority,
            'missing_priority_mappings' => $missing,
        ];
    }

    /**
     * Массовая регистрация маппингов
     */
    public function registerMappings(array $mappings): void
    {
        foreach ($mappings as $objectType => $mapping) {
            if ($mapping instanceof ObjectMapping) {
                $this->registerMapping($objectType, $mapping);
            } else {
                throw new ExchangeMappingException("Invalid mapping provided for {$objectType}");
            }
        }
    }

    /**
     * Проверка конфликтов маппингов
     */
    public function checkMappingConflicts(string $objectType): array
    {
        $conflicts = [];

        // Проверяем точные совпадения
        if (isset($this->mappings[$objectType])) {
            $conflicts[] = [
                'type' => 'exact',
                'pattern' => $objectType,
                'mapping' => $this->mappings[$objectType],
            ];
        }

        // Проверяем паттерны
        foreach ($this->mappings as $pattern => $mapping) {
            if ($pattern !== $objectType && fnmatch($pattern, $objectType)) {
                $conflicts[] = [
                    'type' => 'pattern',
                    'pattern' => $pattern,
                    'mapping' => $mapping,
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Получение маппинга с информацией о конфликтах
     */
    public function getMappingWithConflicts(string $objectType): array
    {
        return [
            'mapping' => $this->getMapping($objectType),
            'conflicts' => $this->checkMappingConflicts($objectType),
            'is_priority' => $this->isPriorityType($objectType),
        ];
    }
}
