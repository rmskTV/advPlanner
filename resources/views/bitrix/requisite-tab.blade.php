<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Выбор реквизита</title>
    <script src="https://api.bitrix24.com/api/v1/"></script>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            padding: 20px;
            margin: 0;
            font-size: 14px;
            background: #fff;
        }
        h3 { margin-top: 0; color: #333; }
        .requisite-card {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .requisite-card:hover {
            border-color: #0fa7d7;
            background: #f8fcff;
        }
        .requisite-card.selected {
            border-color: #0fa7d7;
            background: #e6f7fc;
        }
        .requisite-name {
            font-weight: 600;
            font-size: 15px;
            margin-bottom: 6px;
            color: #333;
        }
        .requisite-details {
            color: #666;
            font-size: 13px;
        }
        .btn {
            background: #0fa7d7;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            margin-top: 15px;
        }
        .btn:hover { background: #0d8eb5; }
        .btn:disabled { background: #ccc; cursor: not-allowed; }
        .no-data {
            padding: 30px;
            text-align: center;
            color: #888;
            background: #f5f5f5;
            border-radius: 8px;
        }
        .status {
            margin-top: 12px;
            padding: 12px;
            border-radius: 4px;
            display: none;
        }
        .status.success {
            display: block;
            background: #e6f9e6;
            color: #2a7d2a;
        }
        .status.error {
            display: block;
            background: #ffe6e6;
            color: #c00;
        }
        .current-badge {
            display: inline-block;
            background: #0fa7d7;
            color: #fff;
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 10px;
            margin-left: 8px;
        }
    </style>
</head>
<body>

@if(!$entityId)
    <div class="no-data">
        Не удалось определить договор.
    </div>
@elseif(!$companyId)
    <div class="no-data">
        К договору не привязан клиент.<br>
        Сначала укажите компанию в договоре.
    </div>
@elseif(empty($requisites))
    <div class="no-data">
        У клиента нет реквизитов.<br><br>
        <a href="https://{{ $domain }}/crm/company/details/{{ $companyId }}/" target="_blank">
            Добавить реквизиты в карточке компании →
        </a>
    </div>
@else
    <h3>Выберите реквизит для договора</h3>

    @foreach($requisites as $req)
        <div class="requisite-card {{ $req['ID'] == $currentRequisiteId ? 'selected' : '' }}"
             data-id="{{ $req['ID'] }}"
             data-name="{{ $req['NAME'] ?: $req['RQ_COMPANY_FULL_NAME'] ?: 'Без названия' }}"
             onclick="selectRequisite(this)">
            <div class="requisite-name">
                {{ $req['NAME'] ?: $req['RQ_COMPANY_FULL_NAME'] ?: 'Без названия' }}
                @if($req['ID'] == $currentRequisiteId)
                    <span class="current-badge">текущий</span>
                @endif
            </div>
            <div class="requisite-details">
                ИНН: {{ $req['RQ_INN'] ?? '—' }}
                @if(!empty($req['RQ_KPP']))
                    &nbsp;/&nbsp; КПП: {{ $req['RQ_KPP'] }}
                @endif
            </div>
        </div>
    @endforeach

    <button class="btn" id="saveBtn" onclick="saveRequisite()">
        Сохранить выбор
    </button>

    <div class="status" id="status"></div>
@endif

<script>
    let selectedRequisiteId = {!! $currentRequisiteId ? $currentRequisiteId : 'null' !!};
    let selectedRequisiteName = '';
    const entityId = {{ $entityId ?? 'null' }};
    const entityTypeId = {{ $entityTypeId ?? 1064 }};
    const requisiteField = '{{ $requisiteField ?? "ufCrm19RequisiteId" }}';

    // Данные договора для формирования названия
    const contractNo = '{{ $contract["ufCrm19ContractNo"] ?? "" }}';
    const contractDate = '{{ isset($contract["ufCrm19ContractDate"]) ? \Carbon\Carbon::parse($contract["ufCrm19ContractDate"])->format("d.m.Y") : "" }}';

    BX24.init(function() {
        BX24.fitWindow();
    });

    function selectRequisite(el) {
        document.querySelectorAll('.requisite-card').forEach(card => {
            card.classList.remove('selected');
        });
        el.classList.add('selected');
        selectedRequisiteId = parseInt(el.dataset.id);
        selectedRequisiteName = el.dataset.name;
    }

    function saveRequisite() {
        if (!selectedRequisiteId || !entityId) {
            alert('Выберите реквизит');
            return;
        }

        const btn = document.getElementById('saveBtn');
        const status = document.getElementById('status');

        btn.disabled = true;
        btn.textContent = 'Сохранение...';
        status.className = 'status';

        // Получаем имя из выбранной карточки прямо сейчас
        const selectedCard = document.querySelector('.requisite-card.selected');
        const requisiteName = selectedCard ? selectedCard.dataset.name : '';

        // Формируем новое название
        const newTitle = `Договор № ${contractNo} от ${contractDate} с ${requisiteName}`;

        let fields = {};
        fields[requisiteField] = selectedRequisiteId;
        fields['title'] = newTitle;

        BX24.callMethod(
            'crm.item.update',
            {
                entityTypeId: entityTypeId,
                id: entityId,
                fields: fields
            },
            function(result) {
                btn.disabled = false;
                btn.textContent = 'Сохранить выбор';

                if (result.error()) {
                    status.className = 'status error';
                    status.textContent = 'Ошибка: ' + result.error_description();
                } else {
                    status.className = 'status success';
                    status.textContent = '✓ Сохранено: ' + newTitle;

                    document.querySelectorAll('.current-badge').forEach(b => b.remove());
                    const selected = document.querySelector('.requisite-card.selected .requisite-name');
                    if (selected) {
                        selected.innerHTML += ' <span class="current-badge">текущий</span>';
                    }

                    // Обновляем заголовок в Б24
                    setTimeout(() => BX24.reload(), 1500);
                }
            }
        );
    }
</script>

</body>
</html>
