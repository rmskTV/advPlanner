<?php

namespace Modules\EnterpriseData\app\Services;

use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use Modules\EnterpriseData\app\Exceptions\ExchangeGenerationException;
use Modules\EnterpriseData\app\Exceptions\ExchangeParsingException;
use Modules\EnterpriseData\app\Models\ExchangeFtpConnector;
use Modules\EnterpriseData\app\ValueObjects\ExchangeBody;
use Modules\EnterpriseData\app\ValueObjects\ExchangeHeader;
use Modules\EnterpriseData\app\ValueObjects\ParsedExchangeMessage;

class ExchangeMessageProcessor
{
    private const MAX_XML_SIZE = 100 * 1024 * 1024; // 100MB

    private const ENTERPRISE_DATA_NAMESPACE = 'http://v8.1c.ru/edi/edi_stnd/EnterpriseData/1.19';

    private const MESSAGE_NAMESPACE = 'http://www.1c.ru/SSL/Exchange/Message';

    public function parseIncomingMessage(string $xmlContent): ParsedExchangeMessage
    {
        try {
            // Удаляем BOM если есть
            $xmlContent = $this->removeBOM($xmlContent);

            // Валидация размера
            if (strlen($xmlContent) > self::MAX_XML_SIZE) {
                throw new ExchangeParsingException('XML content exceeds maximum size limit');
            }

            // Создание DOM документа с безопасными настройками
            $dom = $this->createSecureDomDocument();

            // Отключение внешних сущностей для предотвращения XXE атак
            libxml_disable_entity_loader(true);

            if (! $dom->loadXML($xmlContent, LIBXML_NOCDATA | LIBXML_NONET)) {
                $error = libxml_get_last_error();
                throw new ExchangeParsingException('Invalid XML format: '.($error ? $error->message : 'Unknown XML error'));
            }

            // Создание XPath с namespace-ами
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('msg', self::MESSAGE_NAMESPACE);
            $xpath->registerNamespace('ed', self::ENTERPRISE_DATA_NAMESPACE);

            // Парсинг заголовка
            $header = $this->parseHeader($xpath);

            // Парсинг тела сообщения
            $body = $this->parseBody($xpath);

            return new ParsedExchangeMessage($header, $body);

        } catch (\Exception $e) {
            throw new ExchangeParsingException('Failed to parse exchange message: '.$e->getMessage(), 0, $e);
        } finally {
            libxml_disable_entity_loader(false);
        }
    }

    public function generateOutgoingMessage(
        ExchangeFtpConnector $connector,
        int $messageNo,
        int $receivedNo = 0,
        array $objects = []
    ): string {
        try {
            Log::info('Generating outgoing message', [
                'connector_id' => $connector->id,
                'message_no' => $messageNo,
                'received_no' => $receivedNo,
                'objects_count' => count($objects),
            ]);

            // Создание DOM документа
            $dom = $this->createSecureDomDocument();

            // Корневой элемент Message
            $messageElement = $this->createMessageRootElement($dom);
            $dom->appendChild($messageElement);

            // Заголовок
            $headerElement = $this->generateHeader($dom, $connector, $messageNo, $receivedNo);
            $messageElement->appendChild($headerElement);

            // Тело сообщения
            if (! empty($objects)) {
                $bodyElement = $this->generateBody($dom, $objects);
                $messageElement->appendChild($bodyElement);
            }

            $xmlString = $dom->saveXML();

            Log::info('Generated outgoing message', [
                'connector_id' => $connector->id,
                'message_no' => $messageNo,
                'xml_length' => strlen($xmlString),
            ]);

            return $xmlString;

        } catch (\Exception $e) {
            Log::error('Failed to generate outgoing message', [
                'connector_id' => $connector->id,
                'error' => $e->getMessage(),
            ]);

            throw new ExchangeGenerationException('Failed to generate outgoing message: '.$e->getMessage(), 0, $e);
        }
    }

    public function generateConfirmationOnlyMessage(
        ExchangeFtpConnector $connector,
        int $messageNo,
        int $receivedNo
    ): string {
        try {
            $dom = $this->createSecureDomDocument();

            // Корневой элемент Message
            $messageElement = $this->createMessageRootElement($dom);
            $dom->appendChild($messageElement);

            // Заголовок с подтверждением
            $headerElement = $this->generateHeader($dom, $connector, $messageNo, $receivedNo);
            $messageElement->appendChild($headerElement);

            // Пустое тело сообщения
            $bodyElement = $dom->createElementNS(self::ENTERPRISE_DATA_NAMESPACE, 'Body');
            $messageElement->appendChild($bodyElement);

            $dom->formatOutput = true;

            return $dom->saveXML();

        } catch (\Exception $e) {
            throw new ExchangeParsingException('Failed to generate confirmation message: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Создание корневого элемента Message с namespace-ами
     */
    private function createMessageRootElement(DOMDocument $dom): \DOMElement
    {
        $messageElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'Message');
        $messageElement->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:msg', self::MESSAGE_NAMESPACE);
        $messageElement->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xs', 'http://www.w3.org/2001/XMLSchema');
        $messageElement->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');

        return $messageElement;
    }

    /**
     * Генерация заголовка сообщения (ЕДИНЫЙ метод)
     */
    private function generateHeader(
        DOMDocument $dom,
        ExchangeFtpConnector $connector,
        int $messageNo,
        ?int $receivedNo = null
    ): \DOMElement {
        $headerElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'msg:Header');

        // Формат
        $this->addHeaderElement($dom, $headerElement, 'msg:Format', config('enterprisedata.format'));

        // Дата создания
        $this->addHeaderElement($dom, $headerElement, 'msg:CreationDate', now()->format('Y-m-d\TH:i:s'));

        // Подтверждение
        $confirmationElement = $this->generateConfirmationSection($dom, $connector, $messageNo, $receivedNo);
        $headerElement->appendChild($confirmationElement);

        // Доступные версии
        $this->addAvailableVersions($dom, $headerElement);

        // NewFrom (GUID нашей базы)
        // $this->addHeaderElement($dom, $headerElement, 'msg:NewFrom', config('enterprisedata.own_base_guid'));

        // NewFrom и доступные типы объектов
        $this->addObjectTypes($dom, $headerElement);

        // Префикс
        $this->addHeaderElement($dom, $headerElement, 'msg:prefix', $connector->getOwnBasePrefix());

        return $headerElement;
    }

    /**
     * Генерация секции подтверждения
     */
    private function generateConfirmationSection(
        DOMDocument $dom,
        ExchangeFtpConnector $connector,
        int $messageNo,
        ?int $receivedNo
    ): \DOMElement {
        $confirmationElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'msg:Confirmation');

        $this->addHeaderElement($dom, $confirmationElement, 'msg:ExchangePlan', config('enterprisedata.exchange_plan'));
        $this->addHeaderElement($dom, $confirmationElement, 'msg:To', $connector->getCurrentForeignGuid());
        $this->addHeaderElement($dom, $confirmationElement, 'msg:From', $connector->getOwnBasePrefix());
        $this->addHeaderElement($dom, $confirmationElement, 'msg:MessageNo', (string) $messageNo);

        // ReceivedNo добавляем только если передан
        if ($receivedNo !== null) {
            $this->addHeaderElement($dom, $confirmationElement, 'msg:ReceivedNo', (string) $receivedNo);
        }

        return $confirmationElement;
    }

    /**
     * Добавление доступных версий
     */
    private function addAvailableVersions(DOMDocument $dom, \DOMElement $headerElement): void
    {
        $availableVersions = config('enterprisedata.available_versions_sending', ['1.19']);

        foreach ($availableVersions as $version) {
            $this->addHeaderElement($dom, $headerElement, 'msg:AvailableVersion', $version);
        }
    }

    /**
     * Добавление NewFrom и доступных типов объектов
     */
    private function addObjectTypes(DOMDocument $dom, \DOMElement $headerElement): void
    {
        // Доступные типы объектов
        $this->addAvailableObjectTypes($dom, $headerElement);
    }

    /**
     * Добавление доступных типов объектов
     */
    private function addAvailableObjectTypes(DOMDocument $dom, \DOMElement $headerElement): void
    {
        $objectTypes = config('enterprisedata.available_object_types', []);

        if (empty($objectTypes)) {
            Log::warning('No object types configured');

            return;
        }

        // Контейнер для типов объектов
        $availableObjectTypesElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'msg:AvailableObjectTypes');
        $headerElement->appendChild($availableObjectTypesElement);

        foreach ($objectTypes as $objectType) {
            $objectTypeElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'msg:ObjectType');
            $availableObjectTypesElement->appendChild($objectTypeElement);

            $this->addHeaderElement($dom, $objectTypeElement, 'msg:Name', $objectType['name']);
            $this->addHeaderElement($dom, $objectTypeElement, 'msg:Sending', $objectType['sending'] ?? '');
            $this->addHeaderElement($dom, $objectTypeElement, 'msg:Receiving', $objectType['receiving'] ?? '');
        }

        Log::info('Added available object types successfully', [
            'object_types_count' => count($objectTypes),
        ]);
    }

    /**
     * Универсальный метод добавления элемента заголовка
     */
    private function addHeaderElement(DOMDocument $dom, \DOMElement $parent, string $elementName, string $value): void
    {
        $element = $dom->createElementNS(self::MESSAGE_NAMESPACE, $elementName);
        $element->textContent = $value;
        $parent->appendChild($element);
    }

    /**
     * Генерация тела сообщения
     */
    private function generateBody(DOMDocument $dom, array $objects): \DOMElement
    {
        $bodyElement = $dom->createElement('Body');

        foreach ($objects as $object) {
            $this->addObjectToBody($dom, $bodyElement, $object);
        }

        return $bodyElement;
    }

    /**
     * Добавление объекта в тело сообщения
     */
    private function addObjectToBody(DOMDocument $dom, \DOMElement $bodyElement, array $object): void
    {
        try {
            $objectType = $object['type'] ?? 'UnknownObject';

            // Создаем элемент объекта
            $objectElement = $dom->createElement($objectType);
            $bodyElement->appendChild($objectElement);

            // Добавляем атрибут Ref если есть
            if (! empty($object['ref'])) {
                $objectElement->setAttribute('Ref', $object['ref']);
            }

            // Добавляем свойства объекта
            $properties = $object['properties'] ?? [];
            foreach ($properties as $propertyName => $propertyValue) {
                $this->addPropertyToObject($dom, $objectElement, $propertyName, $propertyValue);
            }

            // Добавляем табличные части
            $tabularSections = $object['tabular_sections'] ?? [];
            foreach ($tabularSections as $sectionName => $sectionRows) {
                $this->addTabularSectionToObject($dom, $objectElement, $sectionName, $sectionRows);
            }

        } catch (\Exception $e) {
            Log::error('Failed to add object to body', [
                'object_type' => $object['type'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            throw new ExchangeGenerationException('Failed to add object to body: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Добавление свойства к объекту
     */
    private function addPropertyToObject(DOMDocument $dom, \DOMElement $objectElement, string $propertyName, $propertyValue): void
    {
        $propertyElement = $dom->createElement($propertyName);
        $objectElement->appendChild($propertyElement);

        if (is_array($propertyValue)) {
            // Если значение - массив, добавляем его элементы рекурсивно
            foreach ($propertyValue as $key => $value) {
                $this->addPropertyToObject($dom, $propertyElement, $key, $value);
            }
        } else {
            // Простое значение
            $propertyElement->textContent = $this->formatValueForXml($propertyValue);

            // Добавление типа если необходимо
            $type = $this->getXmlTypeForValue($propertyValue);
            if ($type) {
                $propertyElement->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:type', $type);
            }
        }
    }

    /**
     * Добавление табличной части к объекту
     */
    private function addTabularSectionToObject(DOMDocument $dom, \DOMElement $objectElement, string $sectionName, array $rows): void
    {
        $sectionElement = $dom->createElement($sectionName);
        $objectElement->appendChild($sectionElement);

        foreach ($rows as $row) {
            $rowElement = $dom->createElement('Строка');
            $sectionElement->appendChild($rowElement);

            if (is_array($row)) {
                foreach ($row as $columnName => $columnValue) {
                    $this->addPropertyToObject($dom, $rowElement, $columnName, $columnValue);
                }
            }
        }
    }

    private function createSecureDomDocument(): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');

        // Безопасные настройки DOM
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        $dom->recover = false;
        $dom->strictErrorChecking = true;

        return $dom;
    }

    private function removeBOM(string $content): string
    {
        // UTF-8 BOM
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }

        // UTF-16 BE BOM
        if (str_starts_with($content, "\xFE\xFF")) {
            return substr($content, 2);
        }

        // UTF-16 LE BOM
        if (str_starts_with($content, "\xFF\xFE")) {
            return substr($content, 2);
        }

        // UTF-32 BE BOM
        if (str_starts_with($content, "\x00\x00\xFE\xFF")) {
            return substr($content, 4);
        }

        // UTF-32 LE BOM
        if (str_starts_with($content, "\xFF\xFE\x00\x00")) {
            return substr($content, 4);
        }

        return $content;
    }

    private function parseHeader(DOMXPath $xpath): ExchangeHeader
    {
        $headerNode = $xpath->query('//msg:Header')->item(0);
        if (! $headerNode) {
            throw new ExchangeParsingException('Header not found in message');
        }

        // Извлечение основных полей заголовка
        $format = $this->getNodeValue($xpath, '//msg:Format', $headerNode);
        $creationDate = $this->parseDate($this->getNodeValue($xpath, '//msg:CreationDate', $headerNode));

        // Информация о подтверждении
        $confirmationNode = $xpath->query('.//msg:Confirmation', $headerNode)->item(0);
        $exchangePlan = '';
        $fromNode = '';
        $toNode = '';
        $messageNo = 0;
        $receivedNo = 0;

        if ($confirmationNode) {
            $exchangePlan = $this->getNodeValue($xpath, './/msg:ExchangePlan', $confirmationNode);
            $fromNode = $this->getNodeValue($xpath, './/msg:From', $confirmationNode);
            $toNode = $this->getNodeValue($xpath, './/msg:To', $confirmationNode);
            $messageNo = (int) $this->getNodeValue($xpath, './/msg:MessageNo', $confirmationNode);
            $receivedNo = (int) $this->getNodeValue($xpath, './/msg:ReceivedNo', $confirmationNode);
        }

        // ДОБАВЛЯЕМ: Извлечение NewFrom
        $newFrom = $this->getNodeValue($xpath, './/msg:NewFrom', $headerNode);

        // Доступные версии
        $availableVersions = [];
        $versionNodes = $xpath->query('.//msg:AvailableVersion', $headerNode);
        foreach ($versionNodes as $versionNode) {
            $availableVersions[] = $versionNode->textContent;
        }

        // Доступные типы объектов
        $availableObjectTypes = [];
        $objectTypeNodes = $xpath->query('.//msg:ObjectType', $headerNode);
        foreach ($objectTypeNodes as $objectTypeNode) {
            $name = $this->getNodeValue($xpath, './/msg:Name', $objectTypeNode);
            $sending = $this->getNodeValue($xpath, './/msg:Sending', $objectTypeNode);
            $receiving = $this->getNodeValue($xpath, './/msg:Receiving', $objectTypeNode);

            $availableObjectTypes[] = [
                'name' => $name,
                'sending' => $sending,
                'receiving' => $receiving,
            ];
        }

        return new ExchangeHeader(
            $format,
            $creationDate,
            $exchangePlan,
            $fromNode,
            $toNode,
            $messageNo,
            $receivedNo,
            $availableVersions,
            $availableObjectTypes,
            $newFrom  // ДОБАВЛЯЕМ новый параметр
        );
    }

    private function parseBody(DOMXPath $xpath): ExchangeBody
    {
        // Ищем элемент Body с разными namespace вариантами
        $bodyNode = $xpath->query('//Body')->item(0) ?:
            $xpath->query('//ed:Body')->item(0) ?:
                $xpath->query('//*[local-name()="Body"]')->item(0);

        Log::info('Parsing message body', [
            'body_node_found' => $bodyNode !== null,
            'body_node_name' => $bodyNode ? $bodyNode->nodeName : null,
            'body_has_children' => $bodyNode ? $bodyNode->hasChildNodes() : false,
            'body_children_count' => $bodyNode ? $bodyNode->childNodes->length : 0,
        ]);

        if (! $bodyNode) {
            Log::warning('Body node not found in XML');

            return new ExchangeBody([]);
        }

        $objects = [];

        // Ищем все элементы с атрибутом Ref (это объекты данных)
        $objectNodes = $xpath->query('.//*[@Ref]', $bodyNode);

        Log::info('Found objects with Ref attribute', [
            'objects_count' => $objectNodes->length,
        ]);

        // Если нет объектов с Ref, ищем все дочерние элементы Body
        if ($objectNodes->length === 0) {
            Log::info('No objects with Ref found, checking all child elements');

            foreach ($bodyNode->childNodes as $childNode) {
                if ($childNode->nodeType === XML_ELEMENT_NODE) {
                    // Парсим как объект даже без Ref
                    $objects[] = $this->parseObject($xpath, $childNode);
                }
            }
        } else {
            // Парсим объекты с Ref
            foreach ($objectNodes as $objectNode) {
                Log::info('Processing object with Ref', [
                    'node_name' => $objectNode->nodeName,
                    'ref' => $objectNode->getAttribute('Ref'),
                ]);

                $objects[] = $this->parseObject($xpath, $objectNode);
            }
        }

        Log::info('Parsed objects from body', [
            'objects_count' => count($objects),
            'object_types' => array_map(fn ($obj) => $obj['type'] ?? 'Unknown', $objects),
        ]);

        return new ExchangeBody($objects);
    }

    private function parseObject(DOMXPath $xpath, \DOMNode $objectNode): array
    {
        $object = [
            'ref' => $objectNode->getAttribute('Ref') ?: null,
            'type' => $objectNode->nodeName,
            'local_name' => $objectNode->localName,
            'namespace_uri' => $objectNode->namespaceURI,
            'properties' => [],
            'tabular_sections' => [],
        ];

        // Парсинг свойств объекта - ПРОСТАЯ ЛОГИКА
        foreach ($objectNode->childNodes as $childNode) {
            if ($childNode->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $propertyName = $childNode->nodeName;

            // Табличные части (старая проверка)
            if ($childNode->hasChildNodes() && $this->isTabularSection($childNode)) {
                $object['tabular_sections'][$propertyName] = $this->parseTabularSection($xpath, $childNode);
            } else {
                // Обычные свойства
                $object['properties'][$propertyName] = $this->parsePropertyValue($childNode);
            }
        }

        return $object;
    }

    private function parseTabularSection(DOMXPath $xpath, \DOMNode $tabularNode): array
    {
        $rows = [];

        foreach ($tabularNode->childNodes as $rowNode) {
            if ($rowNode->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $row = [];
            foreach ($rowNode->childNodes as $cellNode) {
                if ($cellNode->nodeType !== XML_ELEMENT_NODE) {
                    continue;
                }

                $row[$cellNode->nodeName] = $this->parsePropertyValue($cellNode);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Проверка, является ли значение массивом строк
     */
    private function isArrayOfRows(array $value): bool
    {
        if (empty($value)) {
            return false;
        }

        // Проверяем первый элемент
        $firstElement = reset($value);

        return is_array($firstElement) && (
            isset($firstElement['Строка']) ||
            $this->looksLikeTableRow($firstElement)
        );
    }

    /**
     * Проверка, похож ли массив на строку таблицы
     */
    private function looksLikeTableRow(array $data): bool
    {
        // Типичные поля строк табличных частей
        $typicalRowFields = [
            'Номенклатура', 'Количество', 'Цена', 'Сумма',
            'ДанныеНоменклатуры', 'СуммаНДС', 'Содержание',
        ];

        $matchingFields = array_intersect(array_keys($data), $typicalRowFields);

        return count($matchingFields) >= 2; // Если есть хотя бы 2 типичных поля
    }

    private function parsePropertyValue(\DOMNode $node): mixed
    {
        // Определение типа значения по атрибутам или содержимому
        if ($node->hasAttribute('xsi:type')) {
            $type = $node->getAttribute('xsi:type');

            return $this->castValueByType($node->textContent, $type);
        }

        // Если есть дочерние элементы, это сложный объект
        if ($node->hasChildNodes()) {
            $hasElementChildren = false;
            foreach ($node->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE) {
                    $hasElementChildren = true;
                    break;
                }
            }

            if ($hasElementChildren) {
                return $this->parseComplexValue($node);
            }
        }

        return $node->textContent;
    }

    private function parseComplexValue(\DOMNode $node): array
    {
        $result = [];

        foreach ($node->childNodes as $childNode) {
            if ($childNode->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $result[$childNode->nodeName] = $this->parsePropertyValue($childNode);
        }

        return $result;
    }

    private function castValueByType(string $value, string $type): mixed
    {
        return match ($type) {
            'xs:boolean' => $value === 'true',
            'xs:int', 'xs:integer' => (int) $value,
            'xs:decimal', 'xs:double' => (float) $value,
            'xs:dateTime' => $this->parseDate($value),
            'xs:date' => $this->parseDate($value)?->startOfDay(),
            default => $value,
        };
    }

    private function parseDate(string $dateString): ?Carbon
    {
        try {
            return Carbon::parse($dateString);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function isTabularSection(\DOMNode $node): bool
    {
        $childElementNames = [];
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $childElementNames[] = $child->nodeName;
            }
        }

        // ИСПРАВЛЕНИЕ: Табличная часть если есть элементы "Строка"
        return in_array('Строка', $childElementNames);
    }

    private function formatValueForXml(mixed $value): string
    {
        if ($value instanceof Carbon) {
            return $value->toISOString();
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private function getXmlTypeForValue(mixed $value): ?string
    {
        if (is_bool($value)) {
            return 'xs:boolean';
        }

        if (is_int($value)) {
            return 'xs:int';
        }

        if (is_float($value)) {
            return 'xs:decimal';
        }

        if ($value instanceof Carbon) {
            return 'xs:dateTime';
        }

        return null;
    }

    private function getNodeValue(DOMXPath $xpath, string $query, ?\DOMNode $contextNode = null): string
    {
        $nodes = $xpath->query($query, $contextNode);

        return $nodes->length > 0 ? $nodes->item(0)->textContent : '';
    }
}
