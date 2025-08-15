<?php

namespace Modules\EnterpriseData\app\Services;

use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use Modules\EnterpriseData\app\Exceptions\ExchangeParsingException;
use Modules\EnterpriseData\app\Models\ExchangeFtpConnector;
use Modules\EnterpriseData\app\ValueObjects\ExchangeBody;
use Modules\EnterpriseData\app\ValueObjects\ExchangeHeader;
use Modules\EnterpriseData\app\ValueObjects\ParsedExchangeMessage;

class ExchangeMessageProcessor
{
    private const MAX_XML_SIZE = 100 * 1024 * 1024; // 100MB

    private const ENTERPRISE_DATA_NAMESPACE = 'http://v8.1c.ru/edi/edi_stnd/EnterpriseData/1.11';

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

    public function generateOutgoingMessage(
        array $objects1C,
        ExchangeFtpConnector $connector,
        int $messageNo,
        ?int $receivedNo = null // Номер последнего обработанного входящего сообщения
    ): string {
        try {
            $dom = $this->createSecureDomDocument();

            // Корневой элемент Message
            $messageElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'Message');
            $messageElement->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:msg', self::MESSAGE_NAMESPACE);
            $messageElement->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xs', 'http://www.w3.org/2001/XMLSchema');
            $messageElement->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
            $dom->appendChild($messageElement);

            // Заголовок сообщения с подтверждением
            $headerElement = $this->generateHeaderWithConfirmation($dom, $connector, $messageNo, $receivedNo);
            $messageElement->appendChild($headerElement);

            // Тело сообщения
            $bodyElement = $this->generateBody($dom, $objects1C);
            $messageElement->appendChild($bodyElement);

            // Форматирование XML
            $dom->formatOutput = true;
            $xmlContent = $dom->saveXML();

            // Валидация сгенерированного XML
            $this->validateGeneratedXml($xmlContent);

            return $xmlContent;

        } catch (\Exception $e) {
            throw new ExchangeParsingException('Failed to generate exchange message: '.$e->getMessage(), 0, $e);
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
            $messageElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'Message');
            $messageElement->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:msg', self::MESSAGE_NAMESPACE);
            $messageElement->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xs', 'http://www.w3.org/2001/XMLSchema');
            $messageElement->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
            $dom->appendChild($messageElement);

            // Заголовок с подтверждением
            $headerElement = $this->generateHeaderWithConfirmation($dom, $connector, $messageNo, $receivedNo);
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

    private function generateHeaderWithConfirmation(
        DOMDocument $dom,
        ExchangeFtpConnector $connector,
        int $messageNo,
        ?int $receivedNo = null
    ): \DOMElement {
        $headerElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'msg:Header');

        // Формат
        $formatElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'msg:Format');
        $formatElement->textContent = self::ENTERPRISE_DATA_NAMESPACE;
        $headerElement->appendChild($formatElement);

        // Дата создания
        $creationDateElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'msg:CreationDate');
        $creationDateElement->textContent = Carbon::now()->format('Y-m-d\TH:i:s');
        $headerElement->appendChild($creationDateElement);

        // Подтверждение
        $confirmationElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'msg:Confirmation');

        $exchangePlanElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'msg:ExchangePlan');
        $exchangePlanElement->textContent = $connector->exchange_plan_name;
        $confirmationElement->appendChild($exchangePlanElement);

        $toElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'msg:To');
        $toElement->textContent = $connector->foreign_base_guid;
        $confirmationElement->appendChild($toElement);

        $fromElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'msg:From');
        $fromElement->textContent = $connector->getOwnBaseGuid();
        $confirmationElement->appendChild($fromElement);

        $messageNoElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'msg:MessageNo');
        $messageNoElement->textContent = $messageNo;
        $confirmationElement->appendChild($messageNoElement);

        // КЛЮЧЕВОЙ МОМЕНТ: Добавляем ReceivedNo только если есть подтверждаемое сообщение
        if ($receivedNo !== null) {
            $receivedNoElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'msg:ReceivedNo');
            $receivedNoElement->textContent = $receivedNo;
            $confirmationElement->appendChild($receivedNoElement);
        }

        $headerElement->appendChild($confirmationElement);

        // Доступные версии
        foreach ($connector->getAvailableVersionsSending() as $version) {
            $versionElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'msg:AvailableVersion');
            $versionElement->textContent = $version;
            $headerElement->appendChild($versionElement);
        }

        return $headerElement;
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
            $availableObjectTypes
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

        // Парсинг свойств объекта
        foreach ($objectNode->childNodes as $childNode) {
            if ($childNode->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $propertyName = $childNode->nodeName;

            // Табличные части
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
        // Проверка, является ли узел табличной частью
        // Табличная часть обычно содержит повторяющиеся элементы
        $childElementNames = [];
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $childElementNames[] = $child->nodeName;
            }
        }

        return count($childElementNames) > 1 && count(array_unique($childElementNames)) === 1;
    }

    private function generateHeader(DOMDocument $dom, ExchangeFtpConnector $connector, int $messageNo): \DOMElement
    {
        $headerElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'msg:Header');

        // Формат
        $formatElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'msg:Format');
        $formatElement->textContent = self::ENTERPRISE_DATA_NAMESPACE;
        $headerElement->appendChild($formatElement);

        // Дата создания
        $creationDateElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'msg:CreationDate');
        $creationDateElement->textContent = Carbon::now()->format('Y-m-d\TH:i:s');
        $headerElement->appendChild($creationDateElement);

        // Подтверждение
        $confirmationElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'msg:Confirmation');

        $exchangePlanElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'msg:ExchangePlan');
        $exchangePlanElement->textContent = $connector->exchange_plan_name;
        $confirmationElement->appendChild($exchangePlanElement);

        $toElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'msg:To');
        $toElement->textContent = $connector->foreign_base_guid;
        $confirmationElement->appendChild($toElement);

        $fromElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'msg:From');
        $fromElement->textContent = $connector->getOwnBaseGuid();
        $confirmationElement->appendChild($fromElement);

        $messageNoElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'msg:MessageNo');
        $messageNoElement->textContent = $messageNo;
        $confirmationElement->appendChild($messageNoElement);

        $headerElement->appendChild($confirmationElement);

        // Доступные версии
        foreach ($connector->getAvailableVersionsSending() as $version) {
            $versionElement = $dom->createElementNS(self::MESSAGE_NAMESPACE, 'msg:AvailableVersion');
            $versionElement->textContent = $version;
            $headerElement->appendChild($versionElement);
        }

        return $headerElement;
    }

    private function generateBody(DOMDocument $dom, array $objects1C): \DOMElement
    {
        $bodyElement = $dom->createElementNS(self::ENTERPRISE_DATA_NAMESPACE, 'Body');

        foreach ($objects1C as $object) {
            $objectElement = $this->generateObject($dom, $object);
            $bodyElement->appendChild($objectElement);
        }

        return $bodyElement;
    }

    private function generateObject(DOMDocument $dom, array $object): \DOMElement
    {
        $objectElement = $dom->createElement($object['type']);

        if (isset($object['ref'])) {
            $objectElement->setAttribute('Ref', $object['ref']);
        }

        // Генерация свойств
        foreach ($object['properties'] ?? [] as $propertyName => $propertyValue) {
            $propertyElement = $this->generateProperty($dom, $propertyName, $propertyValue);
            $objectElement->appendChild($propertyElement);
        }

        // Генерация табличных частей
        foreach ($object['tabular_sections'] ?? [] as $sectionName => $sectionData) {
            $sectionElement = $this->generateTabularSection($dom, $sectionName, $sectionData);
            $objectElement->appendChild($sectionElement);
        }

        return $objectElement;
    }

    private function generateProperty(DOMDocument $dom, string $name, mixed $value): \DOMElement
    {
        $element = $dom->createElement($name);

        if (is_array($value)) {
            foreach ($value as $key => $subValue) {
                $subElement = $this->generateProperty($dom, $key, $subValue);
                $element->appendChild($subElement);
            }
        } else {
            $element->textContent = $this->formatValueForXml($value);

            // Добавление типа если необходимо
            $type = $this->getXmlTypeForValue($value);
            if ($type) {
                $element->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:type', $type);
            }
        }

        return $element;
    }

    private function generateTabularSection(DOMDocument $dom, string $sectionName, array $rows): \DOMElement
    {
        $sectionElement = $dom->createElement($sectionName);

        foreach ($rows as $row) {
            $rowElement = $dom->createElement('Row');

            foreach ($row as $columnName => $columnValue) {
                $columnElement = $this->generateProperty($dom, $columnName, $columnValue);
                $rowElement->appendChild($columnElement);
            }

            $sectionElement->appendChild($rowElement);
        }

        return $sectionElement;
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

    private function validateGeneratedXml(string $xmlContent): void
    {
        // Базовая валидация XML
        if (strlen($xmlContent) > self::MAX_XML_SIZE) {
            throw new ExchangeParsingException('Generated XML exceeds maximum size limit');
        }

        // Проверка на корректность XML
        $dom = new DOMDocument;
        if (! $dom->loadXML($xmlContent)) {
            throw new ExchangeParsingException('Generated XML is invalid');
        }
    }

    private function getNodeValue(DOMXPath $xpath, string $query, ?\DOMNode $contextNode = null): string
    {
        $nodes = $xpath->query($query, $contextNode);

        return $nodes->length > 0 ? $nodes->item(0)->textContent : '';
    }
}
