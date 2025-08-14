<?php

namespace Modules\EnterpriseData\app\Services;

use Modules\EnterpriseData\app\Exceptions\ExchangeConfigException;
use SimpleXMLElement;

class ExchangeXmlConfigParser
{
    private SimpleXMLElement $xml;

    public function __construct(
        private string $xmlContent
    ) {
        $this->xml = $this->parseXml();
        $this->validate();
    }

    public function getAttributes(): array
    {
        $mainParams = $this->xml->ОсновныеПараметрыОбмена;
        $xdtParams = $this->xml->ПараметрыОбменаXDTO;

        return [
            // Идентификационные параметры
            'own_base_prefix' => $this->parseString($mainParams->ПрефиксИнформационнойБазыИсточника),
            'own_base_name' => $this->parseString($mainParams->НаименованиеЭтойБазы),
            'foreign_base_prefix' => $this->parseString($mainParams->КодНовогоУзлаВторойБазы),
            'foreign_base_name' => $this->parseString($mainParams->НаименованиеВторойБазы),
            'exchange_plan_name' => $this->parseString($mainParams->ИмяПланаОбмена),

            // FTP параметры
            'ftp_path' => $this->parseFtpPath($mainParams->FTPСоединениеПуть),
            'ftp_port' => $this->parsePort($mainParams->FTPСоединениеПорт),
            'ftp_login' => $this->parseString($mainParams->FTPСоединениеПользователь),
            // TODO: Шифровать пароль в продакшене
            'ftp_password' => $this->parseString($mainParams->FTPСоединениеПароль),
            'ftp_passive_mode' => $this->parseBoolean($mainParams->FTPСоединениеПассивноеСоединение),
            'ftp_transliterate' => $this->parseBoolean($mainParams->ТранслитерацияИмениФайловСообщенийОбмена),

            // Формат обмена
            'exchange_format' => $this->parseExchangeFormat($xdtParams->ФорматОбмена),

        ];
    }

    private function parseXml(): SimpleXMLElement
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($this->xmlContent);

        if ($xml === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            throw new ExchangeConfigException(
                'Invalid XML format: '.
                implode(', ', array_map(fn ($e) => $e->message, $errors))
            );
        }

        return $xml;
    }

    private function validate(): void
    {
        // Проверка версии формата
        $version = (string) $this->xml->attributes()['ВерсияФормата'];
        $availableVersions = config('exchange.available_versions_receiving', []);

        if (! is_array($availableVersions) || empty($availableVersions)) {
            throw new ExchangeConfigException('Exchange versions not configured');
        }

        if (! in_array($version, $availableVersions, true)) {
            throw new ExchangeConfigException("Unsupported exchange version: {$version} vs ".implode(',', $availableVersions));
        }

        // Проверка транспортного типа
        $transportType = (string) $this->xml->ОсновныеПараметрыОбмена->ВидТранспортаСообщенийОбмена;
        if ($transportType !== 'FTP') {
            throw new ExchangeConfigException("Unsupported transport type: {$transportType}");
        }

    }

    private function parseString(?SimpleXMLElement $field): string
    {
        return $field !== null ? (string) $field : '';
    }

    private function parseBoolean(?SimpleXMLElement $field): bool
    {
        return $field !== null ? (bool) $field : false;
    }

    private function parseDecimal(?SimpleXMLElement $field): float
    {
        return $field !== null ? (float) $field : 0.0;
    }

    private function parsePort(?SimpleXMLElement $field): int
    {
        $port = $this->parseDecimal($field);

        return ($port > 0 && $port <= 65535) ? (int) $port : 21;
    }

    private function parseFtpPath(?SimpleXMLElement $field): string
    {
        $path = $this->parseString($field);

        return str_starts_with($path, 'ftp://') ? $path : 'ftp://'.$path;
    }

    private function parseExchangeFormat(?SimpleXMLElement $field): string
    {
        $format = $this->parseString($field);

        return match (true) {
            str_contains($format, 'EnterpriseData') => 'EnterpriseData',
            str_contains($format, 'CommerceML') => 'CommerceML',
            default => 'CustomFormat'
        };
    }
}
