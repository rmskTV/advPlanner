<?php

namespace Modules\EnterpriseData\app\Services;

use Illuminate\Http\JsonResponse;
use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use Modules\EnterpriseData\app\Models\ExchangeFtpConnector;
use Modules\EnterpriseData\app\Repositories\ExchangeConnectorRepository;

class ExchangeFtpConnectorService
{
    public function getAll(ExchangeConnectorRepository $repository, array $filters): JsonResponse
    {
        return response()->json($repository->getAll([], $filters), 200);
    }

    public function createFromXml(ExchangeConnectorRepository $repository, string $xmlContent): JsonResponse
    {
        $parser = new ExchangeXmlConfigParser($xmlContent);
        $attributes = $parser->getAttributes();

        return response()->json($repository->create($attributes), 201);
    }

    public function getConnection(ExchangeFtpConnector $connector): Filesystem
    {
        $provider = new SftpConnectionProvider(
            host: parse_url($connector->ftp_path, PHP_URL_HOST),
            username: $connector->ftp_login,
            password: $connector->ftp_password,
            port: $connector->ftp_port,
            useAgent: false,
            timeout: 30,
            maxTries: 3,
            hostFingerprint: null,
            connectivityChecker: null
        );

        $adapter = new SftpAdapter(
            $provider,
            $this->getRootDirectory($connector->ftp_path),
            PortableVisibilityConverter::fromArray([
                'file' => [
                    'public' => 0640,
                    'private' => 0604,
                ],
                'dir' => [
                    'public' => 0740,
                    'private' => 0704,
                ],
            ])
        );

        return new Filesystem(
            $adapter,
            [
                'case_sensitive' => false,
                'disable_asserts' => true,
            ]
        );
    }

    private function getRootDirectory(string $ftpPath): string
    {
        $path = parse_url($ftpPath, PHP_URL_PATH);

        return $path ?: '/';
    }
}
