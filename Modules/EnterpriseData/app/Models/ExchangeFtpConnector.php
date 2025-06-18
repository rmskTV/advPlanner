<?php

namespace Modules\EnterpriseData\app\Models;

use App\Models\CatalogObject;

/**
 * Класс коннектора для обмена данными Enterprise Data через FTP-сервер
 *
 * @property int $id
 * @property string $own_base_prefix Буквенный префикс локальной базы
 * @property string $own_base_name Название локальной базы
 * @property string $foreign_base_prefix Буквенный префикс удаленной базы
 * @property string $foreign_base_guid Глобальный идентификатор (UUID) удаленной базы
 * @property string $foreign_base_name Название удаленной базы
 * @property string $ftp_path Адрес и путь к папке на FTP-сервере
 * @property int $ftp_port Порт для подключения FTP-сервера
 * @property string $ftp_login Логин FTP
 * @property string $ftp_password Пароль FTP
 * @property bool $ftp_passive_mode Использовать пассивный режим
 * @property bool $ftp_transliterate Транслитерировать префиксы узлов в названиях файлов обмена
 * @property string $exchange_plan_name Название плана обмена
 * @property string $exchange_format Формат обмена
 */
class ExchangeFtpConnector extends CatalogObject
{
    protected $table = 'exchange_ftp_connectors';

    protected $hidden = ['ftp_password'];

    protected $guarded = [];

    protected $casts = [
        'ftp_passive_mode' => 'boolean',
        'ftp_transliterate' => 'boolean',
    ];

    public static function getOwnBaseGuid(): string
    {
        return config('exchange.own_base_guid');
    }

    public static function getAvailableVersionsSending(): array
    {
        return config('exchange.available_versions_sending');
    }

    public static function getAvailableVersionsReceiving(): array
    {
        return config('exchange.available_versions_receiving');
    }

    public static function getExchangePlanName(): string
    {
        return config('exchange.exchange_plan_name');
    }
}
