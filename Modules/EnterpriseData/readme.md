**Модуль EnterpriseData**

Модуль для интеграции с системами 1С через протокол обмена EnterpriseData. Обеспечивает двустороннюю синхронизацию справочников и документов между Laravel приложением и 1С.

**Оглавление**

- #архитектура-модуля
- #основные-компоненты
- #конфигурация
- #команды-управления
- #система-маппингов
- #добавление-новых-маппингов
- #мониторинг-и-диагностика
- #устранение-неполадок

**Архитектура модуля**

**Основная схема работы**

```
1С ←→ FTP Server ←→ Laravel App
     XML Files      EnterpriseData Module
```

**Поток данных**

- **Входящий обмен**: 1С → FTP → Laravel
    - 1С создает файл `Message_{1C_prefix}_{Laravel_prefix}.xml`
    - Laravel скачивает и обрабатывает файл
    - Laravel отправляет подтверждение `Message_{Laravel_prefix}_{1C_prefix}.xml`

-  **ДОБАВЛЕНИЕ ОБЪЕКТОВ ИЗ LARAVEL В 1С ЗАЛОЖЕНО НО НЕ РЕАЛИЗОВАНО**
**Исходящий обмен**: Laravel → FTP → 1С
    - Laravel создает файл `Message_{Laravel_prefix}_{1C_prefix}.xml`
    - 1С скачивает и обрабатывает файл
    - 1С отправляет подтверждение

**Основные компоненты**

**1. Модели данных**

**Базовые классы**

- **`Model`** - базовая модель с UUID и soft deletes
- **`CatalogObject`** - для справочников (организации, контрагенты, номенклатура)
- **`Document`** - для документов (заказы, реализации, платежи)
- **`Registry`** - для регистров (состояния заказов)

**Основные модели**

```php
// Справочники
Modules\Accounting\app\Models\Organization                 // Организации
Modules\Accounting\app\Models\Counterparty                // Контрагенты  
Modules\Accounting\app\Models\CounterpartyGroup           // Группы контрагентов
Modules\Accounting\app\Models\Contract                    // Договоры
Modules\Accounting\app\Models\Currency                    // Валюты
Modules\Accounting\app\Models\SystemUser                  // Пользователи 1С
Modules\Accounting\app\Models\UnitOfMeasure              // Единицы измерения
Modules\Accounting\app\Models\ProductGroup               // Группы номенклатуры
Modules\Accounting\app\Models\Product                    // Номенклатура

// Документы
Modules\Accounting\app\Models\CustomerOrder              // Заказы клиентов
Modules\Accounting\app\Models\CustomerOrderItem          // Строки заказов
Modules\Accounting\app\Models\Sale                       // Реализации
Modules\Accounting\app\Models\SaleItem                   // Строки реализаций

// Регистры
Modules\Accounting\app\Models\OrderPaymentStatus         // Состояния оплаты заказов
Modules\Accounting\app\Models\OrderShipmentStatus        // Состояния отгрузки заказов
```

**2. Сервисы**

**ExchangeOrchestrator**

Главный координатор процесса обмена.

**Ключевые методы:**

```php
processIncomingExchange(ExchangeFtpConnector $connector): ExchangeResult
processOutgoingExchange(ExchangeFtpConnector $connector): ExchangeResult
```

**ExchangeFileManager**

Управление файлами на FTP.

**Ключевые методы:**

```php
scanIncomingFiles(ExchangeFtpConnector $connector): array
downloadFile(ExchangeFtpConnector $connector, string $fileName): string
saveOutgoingMessage(ExchangeFtpConnector $connector, string $xmlContent): string
generateOutgoingFileName(ExchangeFtpConnector $connector): string
```

**ExchangeMessageProcessor**

Парсинг и генерация XML сообщений.

**Ключевые методы:**

```php
parseIncomingMessage(string $xmlContent): ParsedExchangeMessage
generateOutgoingMessage(ExchangeFtpConnector $connector, int $messageNo, int $receivedNo, array $objects): string
generateConfirmationOnlyMessage(ExchangeFtpConnector $connector, int $messageNo, int $receivedNo): string
```

**ExchangeDataMapper**

Маппинг объектов между 1С и Laravel.

**Ключевые методы:**

```php
processIncomingObjects(array $objects1C, ExchangeFtpConnector $connector): ProcessingResult
```

**3. Система маппингов**

**ObjectMappingRegistry**

Реестр всех маппингов объектов.

```php
registerMapping(string $objectType, ObjectMapping $mapping): void
getMapping(string $objectType): ?ObjectMapping
hasMapping(string $objectType): bool
```

**Базовый класс ObjectMapping**

```php
abstract class ObjectMapping
{
    abstract public function getObjectType(): string;
    abstract public function getModelClass(): string;
    abstract public function mapFrom1C(array $object1C): Model;
    abstract public function mapTo1C(Model $laravelModel): array;
    abstract public function validateStructure(array $object1C): ValidationResult;
}
```

**4. Value Objects**

- **`ParsedExchangeMessage`** - распарсенное сообщение (header + body)
- **`ExchangeHeader`** - заголовок сообщения
- **`ExchangeBody`** - тело сообщения с объектами
- **`ValidationResult`** - результат валидации
- **`ProcessingResult`** - результат обработки объектов
- **`ExchangeResult`** - итоговый результат обмена

**Конфигурация**

**Основные настройки (config/config.php)**

```php
// Настройки протокола
'format' => 'http://v8.1c.ru/edi/edi_stnd/EnterpriseData/1.11',
'exchange_plan' => 'СинхронизацияДанныхЧерезУниверсальныйФормат',
'own_base_guid' => env('EXCHANGE_DATA_BASE_GUID'),
'own_base_prefix' => env('EXCHANGE_OWN_BASE_PREFIX', 'Ф2'),

// Поддерживаемые версии
'available_versions_sending' => ['1.19', '1.17', '1.16', ...],
'available_versions_receiving' => ['1.19', '1.17', '1.16', ...],

// Поддерживаемые типы объектов
'available_object_types' => [
    [
        'name' => 'Справочник.Организации',
        'sending' => '*',
        'receiving' => '*'
    ],
    // ... остальные типы
],
```

**Переменные окружения (.env)**

```env
EXCHANGE_DATA_BASE_GUID=AB66D2E0-A623-4354-A1DE-504F92D73463
EXCHANGE_OWN_BASE_PREFIX=Ф2
EXCHANGE_PLAN_NAME=СинхронизацияДанныхЧерезУниверсальныйФормат
EXCHANGE_FORMAT=http://v8.1c.ru/edi/edi_stnd/EnterpriseData/1.11
```

**Команды управления**

**Основные команды**

```bash
# Обработка обмена данными
php artisan exchange:process {connector} [--direction=incoming|outgoing|both]

# Просмотр статуса обмена
php artisan exchange:status [connector]

# Тестирование FTP подключения
php artisan exchange:test-connection {connector}
```

**Диагностические команды**

```bash
# Инспекция файла
php artisan exchange:inspect-file {connector} {filename}

# Анализ структуры объекта
php artisan exchange:analyze-object {connector} {filename} {object-type} [--index=0]

# Просмотр немаппированных объектов
php artisan exchange:unmapped-objects {connector}

# Просмотр зарегистрированных маппингов
php artisan exchange:mappings
```

**Служебные команды**

```bash
# Очистка логов обмена
php artisan exchange:cleanup-logs [--days=30]

# Архивирование файлов
php artisan exchange:archive-files {connector} [--file=filename] [--older-than=24]
```

**Система маппингов**

**Существующие маппинги**

| Тип объекта 1С | Модель Laravel | Маппинг-класс |
| --- | --- | --- | 
|Справочник.Организации| Organization| OrganizationMapping|
|Справочник.КонтрагентыГруппа |CounterpartyGroup |CounterpartyGroupMapping|
|Справочник.Контрагенты |Counterparty |CounterpartyMapping|
|Справочник.ФизическиеЛица |Individual |IndividualMapping|
|Справочник.Валюты| Currency |CurrencyMapping|
|Справочник.Пользователи |SystemUser| SystemUserMapping|
|Справочник.ЕдиницыИзмерения| UnitOfMeasure| UnitOfMeasureMapping|
|Справочник.НоменклатураГруппа| ProductGroup| ProductGroupMapping|
|Справочник.Номенклатура |Product |ProductMapping|
|Справочник.Договоры |Contract |ContractMapping|
|Документ.ЗаказКлиента |CustomerOrder |CustomerOrderMapping|
|Документ.РеализацияТоваровУслуг| Sale| SaleMapping|
|Справочник.СостояниеОплатыЗаказа| OrderPaymentStatus| OrderPaymentStatusMapping|
|Справочник.СостояниеОтгрузкиЗаказа| OrderShipmentStatus| OrderShipmentStatusMapping|
УдалениеОбъекта |- |ObjectDeletionMapping|

**Логика updateOrCreate**

Система использует `updateOrCreate` для избежания дублирования записей:

- **Приоритет 1**: GUID 1С (`guid_1c`)
- **Приоритет 2**: Уникальные поля модели (ИНН, номер+дата, название)

**Добавление новых маппингов**

**Шаг 1: Создание миграции**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('new_objects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->timestamps();
            $table->softDeletes();

            // Связь с 1С (обязательно)
            $table->string('guid_1c', 36)->unique()->nullable();

            // Основные поля
            $table->string('name', 255)->nullable();
            // ... другие поля

            // Системные поля (обязательно)
            $table->boolean('deletion_mark')->default(false);
            $table->timestamp('last_sync_at')->nullable();

            // Индексы
            $table->index('name');
            $table->index('deletion_mark');
            $table->index('last_sync_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('new_objects');
    }
};
```

**Шаг 2: Создание модели**

```php
<?php

namespace Modules\Accounting\app\Models;

class NewObject extends CatalogObject // или Document/Registry
{
    protected $table = 'new_objects';

    protected $fillable = [
        'guid_1c',
        'name',
        // ... другие поля
        'deletion_mark',
        'last_sync_at',
    ];

    protected $casts = [
        'deletion_mark' => 'boolean',
        'last_sync_at' => 'datetime',
    ];

    /**
     * Поиск объекта по GUID 1С
     */
    public static function findByGuid1C(string $guid): ?self
    {
        return self::where('guid_1c', $guid)->first();
    }

    /**
     * Обновление времени синхронизации
     */
    public function touchSync(): void
    {
        $this->update(['last_sync_at' => now()]);
    }
}
```

**Шаг 3: Создание маппинга**

```php
<?php

namespace Modules\EnterpriseData\app\Mappings;

use Modules\Accounting\app\Models\NewObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Modules\EnterpriseData\app\Contracts\ObjectMapping;
use Modules\EnterpriseData\app\ValueObjects\ValidationResult;

class NewObjectMapping extends ObjectMapping
{
    public function getObjectType(): string
    {
        return 'Справочник.НовыйОбъект'; // Точное название из 1С
    }

    public function getModelClass(): string
    {
        return NewObject::class;
    }

    public function mapFrom1C(array $object1C): Model
    {
        $properties = $object1C['properties'] ?? [];
        $keyProperties = $properties['КлючевыеСвойства'] ?? [];
        
        $object = new NewObject();
        
        // Обязательные поля
        $object->guid_1c = $this->getFieldValue($keyProperties, 'Ссылка') 
                          ?: ($object1C['ref'] ?? null);
        $object->name = $this->getFieldValue($keyProperties, 'Наименование');
        
        // Дополнительные поля из 1С
        // $object->field = $this->getFieldValue($properties, 'ПолеВ1С');
        
        // Системные поля
        $object->deletion_mark = false;
        $object->last_sync_at = now();

        return $object;
    }

    public function mapTo1C(Model $laravelModel): array
    {
        /** @var NewObject $laravelModel */
        return [
            'type' => 'Справочник.НовыйОбъект',
            'ref' => $laravelModel->guid_1c,
            'properties' => [
                'КлючевыеСвойства' => [
                    'Ссылка' => $laravelModel->guid_1c,
                    'Наименование' => $laravelModel->name,
                ],
                // Дополнительные свойства
            ],
            'tabular_sections' => []
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

        // Проверки полей
        $name = $this->getFieldValue($keyProperties, 'Наименование');
        if (empty(trim($name))) {
            $warnings[] = 'Object name is missing';
        }

        return ValidationResult::success($warnings);
    }
}
```

**Шаг 4: Регистрация маппинга**

В `EnterpriseDataServiceProvider::registerObjectMappings()`:

```php
protected function registerObjectMappings(): void
{
    $registry = $this->app->make(ObjectMappingRegistry::class);
    
    // Существующие маппинги...
    
    // Новый маппинг
    $registry->registerMapping('Справочник.НовыйОбъект', new NewObjectMapping());
}
```

**Шаг 5: Обновление getSearchKeys (если нужно)**

В `ExchangeDataMapper::getSearchKeys()`:

```php
} elseif ($model instanceof \App\Models\NewObject) {
    if (!empty($model->code)) {
        $searchKeys['code'] = $model->code;
    } elseif (!empty($model->name)) {
        $searchKeys['name'] = $model->name;
    }
```

**Шаг 6: Добавление в конфигурацию (опционально)**

В `config/config.php` в секцию `available_object_types`:

```php
[
    'name' => 'Справочник.НовыйОбъект',
    'sending' => '*',
    'receiving' => '*'
],
```

**Мониторинг и диагностика**

**Проверка состояния системы**

```bash
# Общая диагностика
php artisan exchange:diagnose

# Статус конкретного коннектора
php artisan exchange:status 1

# Тестирование подключения
php artisan exchange:test-connection 1
```

**Анализ проблем**

```bash
# Просмотр немаппированных объектов
php artisan exchange:unmapped-objects 1

# Анализ структуры объекта
php artisan exchange:analyze-object 1 "filename.xml" "Справочник.Объект" --index=0

# Инспекция файла
php artisan exchange:inspect-file 1 "filename.xml"
```

**Устранение неполадок**

**Частые проблемы**

**1. Файлы не обрабатываются**

**Проверка:**

```bash
php artisan exchange:test-connection 1
php artisan exchange:inspect-file 1 "filename.xml"
```

**Возможные причины:**

- Неверные настройки FTP
- Неправильное имя файла
- Файл заблокирован

**2. Ошибки маппинга**

**Проверка:**

```bash
php artisan exchange:analyze-object 1 "filename.xml" "ТипОбъекта"
php artisan exchange:mappings
```

**Возможные причины:**

- Отсутствует маппинг для типа объекта
- Неверная структура данных в 1С
- Ошибки валидации

**3. Дублирование записей**

**Проверка:**

- Убедитесь что модель реализует правильные `getSearchKeys()`
- Проверьте уникальность GUID в базе данных

**4. Проблемы с подтверждениями**

**Проверка:**

```bash
# Проверьте что current_foreign_guid обновляется
php artisan tinker
>>> $connector = ExchangeFtpConnector::find(1);
>>> $connector->current_foreign_guid;
```

**Производительность**

**Настройки для больших объемов данных**

```env
EXCHANGE_CHUNK_SIZE=100
EXCHANGE_MAX_EXECUTION_TIME=600
EXCHANGE_MEMORY_LIMIT=512M
EXCHANGE_MAX_OBJECTS_PER_BATCH=500
```

**Мониторинг производительности**

```bash
# Просмотр статистики обработки
php artisan exchange:status 1

# Очистка старых логов
php artisan exchange:cleanup-logs --days=7
```

**Безопасность**

**Защита от XXE атак**

- Отключение внешних сущностей в XML парсере
- Валидация размера файлов
- Ограничение типов обрабатываемых объектов

**Блокировки файлов**

- Файлы блокируются на время обработки
- Автоматическая разблокировка при ошибках
- Таймаут блокировки (по умолчанию 5 минут)

**Валидация данных**

- Проверка структуры XML
- Валидация полей объектов
- Санитизация входящих данных

**Расширение функционала**

**Добавление новых типов документов**

- Создайте миграцию с основной таблицей и таблицей строк
- Создайте модели, наследующие от `Document`
- Создайте маппинг с методом `processTabularSections()`
- Обновите `ExchangeDataMapper::saveOrUpdateModel()` для обработки табличных частей

**Добавление новых справочников**

- Создайте миграцию
- Создайте модель, наследующую от `CatalogObject`
- Создайте простой маппинг
- Зарегистрируйте маппинг

**Добавление новых регистров**

- Создайте миграцию
- Создайте модель, наследующую от `Registry`
- Создайте маппинг для регистра
- Обновите `getSearchKeys()` если нужны специальные ключи поиска

**Версия документации:** 1.0

**Дата обновления:** 18.08.2025

**Автор:** ruslanmoskvitin@gmail.com
