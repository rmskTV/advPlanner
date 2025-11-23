<?php
namespace Modules\Bitrix24\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Bitrix24\app\Services\Bitrix24Service;

class Bitrix24Controller extends Controller
{
    protected $b24Service;

    public function __construct(Bitrix24Service $b24Service)
    {
        $this->b24Service = $b24Service;
    }

    public function deals()
    {
        return $this->b24Service->call('crm.deal.list', [
            'select' => ['ID', 'TITLE']
        ]);
    }

    public function handleWebhook(Request $request)
    {
        // Обработка входящих событий
    }
}
