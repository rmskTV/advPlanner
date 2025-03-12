<script setup>
import GenericCrudTable from '../../../components/catalogObjectsCrudTable/CrudTable.vue';
import BroadcastingDayTemplatesService from "../../../services/BroadcastingDayTemplatesService.js";
import BroadcastingDayTemplateSlotsService from "../../../services/BroadcastingDayTemplateSlotsService.js";
import ChannelService from "../../../services/ChannelService.js";

const columns = [
    {field: 'id', header: 'ID'},
    {field: 'name', header: 'Название'},
    {field: 'channel.name', header: 'Канал'},
];

const formFields = [
    [{type: 'text', name: 'name', label: 'Название'}],
    [{type: 'integer', name: 'start_hour', label: 'Начальный час', min: 0, max: 23, default: 6},
    {type: 'select', name: 'channel_id', label: 'Канал', optionsService: ChannelService }],
];

const filters = [
    {
        name: 'Канал',
        type: 'select',
        optionsService: ChannelService,
        queryName: 'channel_id'
    }
];

const hasManyRelations = [
    {
        name: "broadcastingDayTemplateSlots",
        label: "Слоты",
        service: BroadcastingDayTemplateSlotsService,
        columns: [
            { field: "start", header: "Начало" },
            { field: "end", header: "Окончание" },
            { field: "name", header: "Название" },
        ],
        formFields: [
            [{ type: "text", name: "name", label: "Название" }],
            [{ type: "integer", name: "start", label: "Начало (минут с начала шаблона)" }],
            [{ type: "integer", name: "end", label: "Окончание (минут с начала шаблона)" }],
        ],
        filters: [],
        parentFilterName: "broadcasting_day_template_id", // Имя фильтра для parentId
    },
];

</script>

<template>
    <GenericCrudTable
        :service="BroadcastingDayTemplatesService"
        :columns="columns"
        :formFields="formFields"
        title="Суточные шаблоны вещания"
        :filters="filters"
        :hasManyRelations="hasManyRelations"
    />
</template>
