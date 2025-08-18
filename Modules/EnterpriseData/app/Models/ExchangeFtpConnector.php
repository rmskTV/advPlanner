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
        return config('enterprisedata.own_base_guid');
    }

    public static function getAvailableVersionsSending(): array
    {
        return config('enterprisedata.available_versions_sending');
    }

    public static function getAvailableVersionsReceiving(): array
    {
        return config('enterprisedata.available_versions_receiving');
    }

    public static function getExchangePlanName(): string
    {
        return config('enterprisedata.exchange_plan_name');
    }

    public function getOwnBasePrefix(): string
    {
        return $this->own_base_prefix;
    }

    /**
     * Получение актуального GUID для отправки сообщений
     */
    public function getCurrentForeignGuid(): string
    {
        return $this->current_foreign_guid ?: $this->foreign_base_guid;
    }

    /**
     * Обновление текущего GUID внешней базы
     */
    public function updateCurrentForeignGuid(string $newGuid): void
    {
        if ($newGuid !== $this->current_foreign_guid) {

            $this->update(['current_foreign_guid' => $newGuid]);
        }
    }


    /**
     * Получение следующего номера исходящего сообщения
     */
    public function getNextOutgoingMessageNo(): int
    {
        $nextNo = $this->last_outgoing_message_no + 1;
        return $nextNo;
    }

    /**
     * Обновление номера последнего исходящего сообщения
     */
    public function updateLastOutgoingMessageNo(int $messageNo): void
    {
        $this->update(['last_outgoing_message_no' => $messageNo]);
    }

}
