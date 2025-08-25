<?php

namespace Modules\EnterpriseData\Tests\Unit\Services;

use Modules\EnterpriseData\app\Exceptions\ExchangeParsingException;
use Modules\EnterpriseData\app\Models\ExchangeFtpConnector;
use Modules\EnterpriseData\app\Services\ExchangeMessageProcessor;
use Tests\TestCase;

class ExchangeMessageProcessorTest extends TestCase
{
    private ExchangeMessageProcessor $processor;

    private ExchangeFtpConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new ExchangeMessageProcessor;
        $this->connector = $this->createTestConnector();
    }

    public function test_parses_valid_xml_message(): void
    {
        $xmlContent = $this->getValidXmlMessage();

        $result = $this->processor->parseIncomingMessage($xmlContent);

        $this->assertNotNull($result);
        $this->assertEquals('EnterpriseData', $result->header->format);
        $this->assertEquals('TestExchange', $result->header->exchangePlan);
        $this->assertEquals(1, $result->header->messageNo);
        $this->assertGreaterThan(0, $result->body->getObjectsCount());
    }

    public function test_handles_bom_in_xml(): void
    {
        $xmlWithBom = "\xEF\xBB\xBF".$this->getValidXmlMessage();

        $result = $this->processor->parseIncomingMessage($xmlWithBom);

        $this->assertNotNull($result);
        $this->assertEquals('EnterpriseData', $result->header->format);
    }

    public function test_throws_exception_for_invalid_xml(): void
    {
        $this->expectException(ExchangeParsingException::class);

        $this->processor->parseIncomingMessage('Invalid XML content');
    }

    public function test_generates_outgoing_message(): void
    {
        $objects = [
            [
                'type' => 'Справочник.Организации',
                'ref' => 'test-guid',
                'properties' => ['Наименование' => 'Test Organization'],
                'tabular_sections' => [],
            ],
        ];

        $result = $this->processor->generateOutgoingMessage($this->connector, 1, 0, $objects);

        $this->assertStringContainsString('<?xml', $result);
        $this->assertStringContainsString('Message', $result);
        $this->assertStringContainsString('Test Organization', $result);

        // Проверяем что XML валидный
        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML($result));
    }

    public function test_generates_confirmation_only_message(): void
    {
        $result = $this->processor->generateConfirmationOnlyMessage($this->connector, 1, 5);

        $this->assertStringContainsString('<?xml', $result);
        $this->assertStringContainsString('ReceivedNo>5</msg:ReceivedNo>', $result);

        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML($result));
    }

    public function test_parses_objects_from_body(): void
    {
        $xmlContent = $this->getXmlWithMultipleObjects();

        $result = $this->processor->parseIncomingMessage($xmlContent);

        $this->assertEquals(2, $result->body->getObjectsCount());

        $orgObjects = $result->body->getObjectsByType('Справочник.Организации');
        $this->assertCount(1, $orgObjects);

        $contractObjects = $result->body->getObjectsByType('Справочник.Договоры');
        $this->assertCount(1, $contractObjects);
    }

    private function createTestConnector(): ExchangeFtpConnector
    {
        $connector = new ExchangeFtpConnector;
        $connector->own_base_prefix = 'TEST';
        $connector->foreign_base_prefix = 'EXT';
        $connector->foreign_base_guid = 'test-foreign-guid';
        $connector->current_foreign_guid = 'current-foreign-guid';

        return $connector;
    }

    private function getValidXmlMessage(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
        <Message xmlns:msg="http://www.1c.ru/SSL/Exchange/Message">
            <msg:Header>
                <msg:Format>EnterpriseData</msg:Format>
                <msg:CreationDate>2024-01-01T10:00:00</msg:CreationDate>
                <msg:Confirmation>
                    <msg:ExchangePlan>TestExchange</msg:ExchangePlan>
                    <msg:From>EXT</msg:From>
                    <msg:To>TEST</msg:To>
                    <msg:MessageNo>1</msg:MessageNo>
                    <msg:ReceivedNo>0</msg:ReceivedNo>
                </msg:Confirmation>
                <msg:AvailableVersion>1.11</msg:AvailableVersion>
            </msg:Header>
            <Body>
                <Справочник.Организации Ref="org-guid-1">
                    <КлючевыеСвойства>
                        <Ссылка>org-guid-1</Ссылка>
                        <Наименование>Test Organization</Наименование>
                    </КлючевыеСвойства>
                </Справочник.Организации>
            </Body>
        </Message>';
    }

    private function getXmlWithMultipleObjects(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
        <Message xmlns:msg="http://www.1c.ru/SSL/Exchange/Message">
            <msg:Header>
                <msg:Format>EnterpriseData</msg:Format>
                <msg:CreationDate>2024-01-01T10:00:00</msg:CreationDate>
                <msg:Confirmation>
                    <msg:ExchangePlan>TestExchange</msg:ExchangePlan>
                    <msg:From>EXT</msg:From>
                    <msg:To>TEST</msg:To>
                    <msg:MessageNo>1</msg:MessageNo>
                </msg:Confirmation>
                <msg:AvailableVersion>1.11</msg:AvailableVersion>
            </msg:Header>
            <Body>
                <Справочник.Организации Ref="org-guid-1">
                    <КлючевыеСвойства>
                        <Наименование>Test Organization</Наименование>
                    </КлючевыеСвойства>
                </Справочник.Организации>
                <Справочник.Договоры Ref="contract-guid-1">
                    <КлючевыеСвойства>
                        <Наименование>Test Contract</Наименование>
                    </КлючевыеСвойства>
                </Справочник.Договоры>
            </Body>
        </Message>';
    }
}
