<?php

namespace Modules\EnterpriseData\app\Services;

use Modules\EnterpriseData\app\Models\ExchangeFtpConnector;
use Modules\EnterpriseData\app\ValueObjects\ValidationResult;

class ExchangeConfigValidator
{
    private const MIN_PASSWORD_LENGTH = 12;

    private const WEAK_PASSWORDS = ['password', '123456', 'qwerty', 'admin'];

    public function validateConnector(ExchangeFtpConnector $connector): ValidationResult
    {
        $errors = [];
        $warnings = [];

        // Валидация FTP настроек
        $ftpValidation = $this->validateFtpSettings($connector);
        $errors = array_merge($errors, $ftpValidation->getErrors());
        $warnings = array_merge($warnings, $ftpValidation->getWarnings());

        // Валидация безопасности
        $securityValidation = $this->validateSecuritySettings($connector);
        $errors = array_merge($errors, $securityValidation->getErrors());
        $warnings = array_merge($warnings, $securityValidation->getWarnings());

        // Валидация формата обмена
        $formatValidation = $this->validateExchangeFormat($connector);
        $errors = array_merge($errors, $formatValidation->getErrors());

        return new ValidationResult(empty($errors), $errors, $warnings);
    }

    public function validateFtpConnection(ExchangeFtpConnector $connector): bool
    {
        try {
            $filesystem = app(ExchangeFtpConnectorService::class)->getConnection($connector);

            // Проверка доступности корневой директории
            if (! $filesystem->directoryExists('/')) {
                return false;
            }

            // Проверка возможности создания тестового файла
            $testContent = '<?xml version="1.0" encoding="UTF-8"?><test>connection</test>';
            $testFileName = 'connection_test_'.time().'.xml';

            $filesystem->write($testFileName, $testContent);
            $readContent = $filesystem->read($testFileName);
            $filesystem->delete($testFileName);

            return $readContent === $testContent;

        } catch (\Exception $e) {
            Log::error('FTP connection validation failed', [
                'connector' => $connector->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function validateFtpSettings(ExchangeFtpConnector $connector): ValidationResult
    {
        $errors = [];
        $warnings = [];

        // Валидация хоста
        if (empty($connector->ftp_path)) {
            $errors[] = 'FTP path is required';
        } else {
            $parsedUrl = parse_url($connector->ftp_path);
            if (! $parsedUrl || empty($parsedUrl['host'])) {
                $errors[] = 'Invalid FTP path format';
            }
        }

        // Валидация порта
        if ($connector->ftp_port < 1 || $connector->ftp_port > 65535) {
            $errors[] = 'Invalid FTP port';
        }

        // Валидация учетных данных
        if (empty($connector->ftp_login)) {
            $errors[] = 'FTP login is required';
        }

        if (empty($connector->ftp_password)) {
            $errors[] = 'FTP password is required';
        }

        return new ValidationResult(empty($errors), $errors, $warnings);
    }

    private function validateSecuritySettings(ExchangeFtpConnector $connector): ValidationResult
    {
        $errors = [];
        $warnings = [];

        // Проверка силы пароля
        if (strlen($connector->ftp_password) < self::MIN_PASSWORD_LENGTH) {
            $warnings[] = 'FTP password should be at least '.self::MIN_PASSWORD_LENGTH.' characters long';
        }

        if (in_array(strtolower($connector->ftp_password), self::WEAK_PASSWORDS)) {
            $errors[] = 'FTP password is too weak';
        }

        // Проверка использования SFTP
        $parsedUrl = parse_url($connector->ftp_path);
        if (isset($parsedUrl['scheme']) && $parsedUrl['scheme'] === 'ftp') {
            $warnings[] = 'Consider using SFTP instead of FTP for better security';
        }

        // Проверка порта по умолчанию
        if ($connector->ftp_port === 21) {
            $warnings[] = 'Consider using non-standard port for better security';
        }

        return new ValidationResult(empty($errors), $errors, $warnings);
    }

    private function validateExchangeFormat(ExchangeFtpConnector $connector): ValidationResult
    {
        $errors = [];

        $supportedFormats = ['EnterpriseData', 'CommerceML'];
        if (! in_array($connector->exchange_format, $supportedFormats)) {
            $errors[] = 'Unsupported exchange format: '.$connector->exchange_format;
        }

        return new ValidationResult(empty($errors), $errors, []);
    }
}
