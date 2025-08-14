<?php

namespace Modules\EnterpriseData\app\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use JsonSerializable;

/**
 * Value Object для результатов валидации
 *
 * Инкапсулирует результат валидации с информацией о статусе,
 * ошибках и предупреждениях
 */
readonly class ValidationResult implements Arrayable, Jsonable, JsonSerializable
{
    /**
     * @param  bool  $valid  Статус валидации (true = валидно, false = есть ошибки)
     * @param  array  $errors  Массив ошибок валидации
     * @param  array  $warnings  Массив предупреждений валидации
     * @param  array  $context  Дополнительный контекст валидации
     */
    public function __construct(
        private bool $valid,
        private array $errors = [],
        private array $warnings = [],
        private array $context = []
    ) {}

    /**
     * Создание успешного результата валидации
     */
    public static function success(array $warnings = [], array $context = []): self
    {
        return new self(true, [], $warnings, $context);
    }

    /**
     * Создание неуспешного результата валидации
     */
    public static function failure(
        array $errors,
        array $warnings = [],
        array $context = []
    ): self {
        return new self(false, $errors, $warnings, $context);
    }

    /**
     * Создание результата с одной ошибкой
     */
    public static function withSingleError(
        string $error,
        array $warnings = [],
        array $context = []
    ): self {
        return new self(false, [$error], $warnings, $context);
    }

    /**
     * Создание результата с одним предупреждением
     */
    public static function withSingleWarning(
        string $warning,
        array $context = []
    ): self {
        return new self(true, [], [$warning], $context);
    }

    /**
     * Проверка валидности результата
     */
    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * Проверка наличия ошибок
     */
    public function hasErrors(): bool
    {
        return ! empty($this->errors);
    }

    /**
     * Проверка наличия предупреждений
     */
    public function hasWarnings(): bool
    {
        return ! empty($this->warnings);
    }

    /**
     * Проверка наличия контекста
     */
    public function hasContext(): bool
    {
        return ! empty($this->context);
    }

    /**
     * Получение массива ошибок
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Получение массива предупреждений
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Получение контекста валидации
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Получение первой ошибки
     */
    public function getFirstError(): ?string
    {
        return $this->errors[0] ?? null;
    }

    /**
     * Получение первого предупреждения
     */
    public function getFirstWarning(): ?string
    {
        return $this->warnings[0] ?? null;
    }

    /**
     * Получение количества ошибок
     */
    public function getErrorCount(): int
    {
        return count($this->errors);
    }

    /**
     * Получение количества предупреждений
     */
    public function getWarningCount(): int
    {
        return count($this->warnings);
    }

    /**
     * Получение всех сообщений (ошибки + предупреждения)
     */
    public function getAllMessages(): array
    {
        return array_merge($this->errors, $this->warnings);
    }

    /**
     * Получение значения из контекста
     */
    public function getContextValue(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }

    /**
     * Объединение с другим результатом валидации
     */
    public function merge(ValidationResult $other): self
    {
        return new self(
            $this->valid && $other->valid,
            array_merge($this->errors, $other->errors),
            array_merge($this->warnings, $other->warnings),
            array_merge($this->context, $other->context)
        );
    }

    /**
     * Добавление ошибки к текущему результату (возвращает новый объект)
     */
    public function addError(string $error): self
    {
        return new self(
            false, // При добавлении ошибки результат становится невалидным
            array_merge($this->errors, [$error]),
            $this->warnings,
            $this->context
        );
    }

    /**
     * Добавление предупреждения к текущему результату (возвращает новый объект)
     */
    public function addWarning(string $warning): self
    {
        return new self(
            $this->valid,
            $this->errors,
            array_merge($this->warnings, [$warning]),
            $this->context
        );
    }

    /**
     * Добавление контекста к текущему результату (возвращает новый объект)
     */
    public function addContext(string $key, mixed $value): self
    {
        return new self(
            $this->valid,
            $this->errors,
            $this->warnings,
            array_merge($this->context, [$key => $value])
        );
    }

    /**
     * Добавление массива контекста к текущему результату (возвращает новый объект)
     */
    public function addContextArray(array $context): self
    {
        return new self(
            $this->valid,
            $this->errors,
            $this->warnings,
            array_merge($this->context, $context)
        );
    }

    /**
     * Фильтрация ошибок по паттерну
     */
    public function getErrorsContaining(string $pattern): array
    {
        return array_filter($this->errors, fn ($error) => str_contains($error, $pattern));
    }

    /**
     * Фильтрация предупреждений по паттерну
     */
    public function getWarningsContaining(string $pattern): array
    {
        return array_filter($this->warnings, fn ($warning) => str_contains($warning, $pattern));
    }

    /**
     * Получение краткого описания результата
     */
    public function getSummary(): string
    {
        if ($this->valid) {
            if ($this->hasWarnings()) {
                return sprintf(
                    'Valid with %d warning(s)',
                    $this->getWarningCount()
                );
            }

            return 'Valid';
        }

        $parts = [sprintf('%d error(s)', $this->getErrorCount())];

        if ($this->hasWarnings()) {
            $parts[] = sprintf('%d warning(s)', $this->getWarningCount());
        }

        return 'Invalid: '.implode(', ', $parts);
    }

    /**
     * Проверка наличия конкретной ошибки
     */
    public function hasError(string $error): bool
    {
        return in_array($error, $this->errors, true);
    }

    /**
     * Проверка наличия конкретного предупреждения
     */
    public function hasWarning(string $warning): bool
    {
        return in_array($warning, $this->warnings, true);
    }

    /**
     * Проверка наличия ошибки по паттерну
     */
    public function hasErrorContaining(string $pattern): bool
    {
        return ! empty($this->getErrorsContaining($pattern));
    }

    /**
     * Проверка наличия предупреждения по паттерну
     */
    public function hasWarningContaining(string $pattern): bool
    {
        return ! empty($this->getWarningsContaining($pattern));
    }

    /**
     * Создание результата на основе условия
     */
    public static function when(
        bool $condition,
        string $errorMessage = 'Validation failed',
        array $context = []
    ): self {
        return $condition
            ? self::success([], $context)
            : self::withSingleError($errorMessage, [], $context);
    }

    /**
     * Создание результата на основе массива условий
     */
    public static function fromConditions(array $conditions): self
    {
        $errors = [];
        $warnings = [];
        $context = [];

        foreach ($conditions as $condition => $message) {
            if (is_array($message)) {
                // Расширенный формат: ['condition' => ['error' => '...', 'warning' => '...', 'context' => [...]]]
                if (! $condition) {
                    if (isset($message['error'])) {
                        $errors[] = $message['error'];
                    }
                }
                if (isset($message['warning'])) {
                    $warnings[] = $message['warning'];
                }
                if (isset($message['context'])) {
                    $context = array_merge($context, $message['context']);
                }
            } else {
                // Простой формат: ['condition' => 'error message']
                if (! $condition) {
                    $errors[] = $message;
                }
            }
        }

        return empty($errors)
            ? self::success($warnings, $context)
            : self::failure($errors, $warnings, $context);
    }

    /**
     * Преобразование в массив
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'context' => $this->context,
            'error_count' => $this->getErrorCount(),
            'warning_count' => $this->getWarningCount(),
            'summary' => $this->getSummary(),
        ];
    }

    /**
     * Преобразование в JSON
     */
    public function toJson($options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Данные для JSON сериализации
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Строковое представление результата
     */
    public function __toString(): string
    {
        return $this->getSummary();
    }

    /**
     * Создание результата из исключения
     */
    public static function fromException(\Throwable $exception, array $context = []): self
    {
        return self::withSingleError(
            $exception->getMessage(),
            [],
            array_merge($context, [
                'exception_class' => get_class($exception),
                'exception_code' => $exception->getCode(),
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
            ])
        );
    }

    /**
     * Создание результата из Laravel Validator
     */
    public static function fromValidator(\Illuminate\Validation\Validator $validator): self
    {
        $errors = [];
        foreach ($validator->errors()->all() as $error) {
            $errors[] = $error;
        }

        return empty($errors)
            ? self::success()
            : self::failure($errors, [], ['validated_data' => $validator->validated()]);
    }

    /**
     * Применение функции к результату (функциональный подход)
     */
    public function map(callable $callback): self
    {
        return $callback($this);
    }

    /**
     * Выполнение действия только при успешной валидации
     */
    public function onSuccess(callable $callback): self
    {
        if ($this->valid) {
            $callback($this);
        }

        return $this;
    }

    /**
     * Выполнение действия только при неуспешной валидации
     */
    public function onFailure(callable $callback): self
    {
        if (! $this->valid) {
            $callback($this);
        }

        return $this;
    }

    /**
     * Выполнение действия при наличии предупреждений
     */
    public function onWarnings(callable $callback): self
    {
        if ($this->hasWarnings()) {
            $callback($this);
        }

        return $this;
    }

    /**
     * Создание пустого результата (без ошибок и предупреждений)
     */
    public static function empty(): self
    {
        return new self(true, [], [], []);
    }

    /**
     * Проверка, является ли результат пустым
     */
    public function isEmpty(): bool
    {
        return $this->valid && empty($this->errors) && empty($this->warnings);
    }

    /**
     * Получение детального описания с перечислением всех ошибок и предупреждений
     */
    public function getDetailedSummary(): string
    {
        $parts = [$this->getSummary()];

        if ($this->hasErrors()) {
            $parts[] = 'Errors: '.implode('; ', $this->errors);
        }

        if ($this->hasWarnings()) {
            $parts[] = 'Warnings: '.implode('; ', $this->warnings);
        }

        return implode('. ', $parts);
    }
}
