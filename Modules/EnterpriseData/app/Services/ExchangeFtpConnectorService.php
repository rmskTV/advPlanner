<?php

namespace Modules\EnterpriseData\app\Services;

use Illuminate\Support\Facades\Log;
use League\Flysystem\Filesystem;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use Modules\EnterpriseData\app\Exceptions\ExchangeFileException;
use Modules\EnterpriseData\app\Models\ExchangeFtpConnector;

class ExchangeFtpConnectorService
{
    private array $connections = [];

    public function getConnection(ExchangeFtpConnector $connector): Filesystem
    {
        $connectionKey = "connector_{$connector->id}";

        if (! isset($this->connections[$connectionKey])) {
            $this->connections[$connectionKey] = $this->createConnection($connector);
        }

        return $this->connections[$connectionKey];
    }

    private function createConnection(ExchangeFtpConnector $connector): Filesystem
    {
        try {
            // Парсинг FTP URL
            $parsedUrl = parse_url($connector->ftp_path);

            if (! $parsedUrl || ! isset($parsedUrl['host'])) {
                throw new ExchangeFileException("Invalid FTP path: {$connector->ftp_path}");
            }

            $host = $parsedUrl['host'];
            $port = $connector->ftp_port ?: ($parsedUrl['port'] ?? 21);

            Log::info('Creating FTP connection', [
                'connector' => $connector->id,
                'host' => $host,
                'port' => $port,
                'login' => $connector->ftp_login,
                'passive_mode' => $connector->ftp_passive_mode,
                'transliterate' => $connector->ftp_transliterate,
            ]);

            // Создание опций подключения
            $options = FtpConnectionOptions::fromArray([
                'host' => $host,
                'port' => $port,
                'username' => $connector->ftp_login,
                'password' => $connector->ftp_password,
                'passive' => $connector->ftp_passive_mode,
                'ssl' => false, // Можно добавить как свойство коннектора
                'timeout' => 30,
                'utf8' => true,
                'ignorePassiveAddress' => false,
                'timestampsOnUnixListingsEnabled' => true,
                'recurseManually' => true,
            ]);

            // Создание адаптера
            $adapter = new FtpAdapter($options);

            // Создание файловой системы
            $filesystem = new Filesystem($adapter);

            // Тест подключения
            try {
                $exchangePath = $this->getExchangePath($connector);

                // Пытаемся получить листинг корневой директории или exchange path
                $testPath = empty($exchangePath) || $exchangePath === '.' ? '/' : $exchangePath;

                Log::info('Testing directory listing', [
                    'connector' => $connector->id,
                    'test_path' => $testPath,
                ]);

                $listing = $filesystem->listContents($testPath, false);

                // Просто проверяем, что можем получить итератор
                $listing->toArray(); // Это заставит выполниться запрос

                Log::info('FTP connection established successfully', [
                    'connector' => $connector->id,
                ]);

            } catch (\Exception $e) {
                Log::error('FTP connection test failed', [
                    'connector' => $connector->id,
                    'test_path' => $testPath ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }

            return $filesystem;

        } catch (\Exception $e) {
            Log::error('Failed to create FTP connection', [
                'connector' => $connector->id,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            throw new ExchangeFileException('Failed to connect to FTP: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Получение пути для обмена из ftp_path
     */
    private function getExchangePath(ExchangeFtpConnector $connector): string
    {
        $parsedUrl = parse_url($connector->ftp_path);

        // Если есть path в URL, используем его
        $path = $parsedUrl['path'] ?? '';

        // Убираем начальные и конечные слеши
        $path = trim($path, '/');

        // Если путь пустой, работаем в корневой директории
        return $path;
    }

    /**
     * Тест подключения с детальной диагностикой
     */
    public function testConnectionDetailed(ExchangeFtpConnector $connector): array
    {
        $result = [
            'success' => false,
            'steps' => [],
            'error' => null,
        ];

        try {
            // Шаг 1: Парсинг URL
            $parsedUrl = parse_url($connector->ftp_path);
            if (! $parsedUrl || ! isset($parsedUrl['host'])) {
                $result['steps'][] = ['step' => 'parse_url', 'success' => false, 'error' => 'Invalid FTP URL'];
                $result['error'] = 'Invalid FTP URL';

                return $result;
            }
            $result['steps'][] = ['step' => 'parse_url', 'success' => true];

            $host = $parsedUrl['host'];
            $port = $connector->ftp_port ?: ($parsedUrl['port'] ?? 21);

            // Шаг 2: Создание опций подключения
            $options = FtpConnectionOptions::fromArray([
                'host' => $host,
                'port' => $port,
                'username' => $connector->ftp_login,
                'password' => $connector->ftp_password,
                'passive' => $connector->ftp_passive_mode,
                'ssl' => false,
                'timeout' => 10, // Уменьшенный таймаут для теста
            ]);
            $result['steps'][] = ['step' => 'create_options', 'success' => true];

            // Шаг 3: Создание адаптера
            $adapter = new FtpAdapter($options);
            $result['steps'][] = ['step' => 'create_adapter', 'success' => true];

            // Шаг 4: Создание файловой системы
            $filesystem = new Filesystem($adapter);
            $result['steps'][] = ['step' => 'create_filesystem', 'success' => true];

            // Шаг 5: Тест листинга корневой директории
            try {
                $rootListing = $filesystem->listContents('/', false);
                $rootItems = $rootListing->toArray();
                $result['steps'][] = [
                    'step' => 'list_root',
                    'success' => true,
                    'items_count' => count($rootItems),
                ];
            } catch (\Exception $e) {
                $result['steps'][] = [
                    'step' => 'list_root',
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }

            // Шаг 6: Тест листинга exchange директории
            $exchangePath = $this->getExchangePath($connector);
            if (! empty($exchangePath) && $exchangePath !== '.') {
                try {
                    $exchangeListing = $filesystem->listContents($exchangePath, false);
                    $exchangeItems = $exchangeListing->toArray();
                    $result['steps'][] = [
                        'step' => 'list_exchange_path',
                        'success' => true,
                        'path' => $exchangePath,
                        'items_count' => count($exchangeItems),
                    ];
                } catch (\Exception $e) {
                    $result['steps'][] = [
                        'step' => 'list_exchange_path',
                        'success' => false,
                        'path' => $exchangePath,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            $result['success'] = true;

        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            $result['steps'][] = [
                'step' => 'connection_failed',
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }

        return $result;
    }

    private function isValidFileExtension(string $fileName): bool
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return in_array($extension, self::ALLOWED_EXTENSIONS, true);
    }

    private function transliterate(string $text): string
    {
        $transliterationMap = [
            'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D',
            'Е' => 'E', 'Ё' => 'E', 'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I',
            'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N',
            'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T',
            'У' => 'U', 'Ф' => 'F', 'Х' => 'Kh', 'Ц' => 'Ts', 'Ч' => 'Ch',
            'Ш' => 'Sh', 'Щ' => 'Sch', 'Ъ' => '', 'Ы' => 'Y', 'Ь' => '',
            'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya',
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
            'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
            'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
            'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ];

        return strtr($text, $transliterationMap);
    }
}
