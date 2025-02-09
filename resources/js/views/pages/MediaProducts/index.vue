<script setup>
import GenericCrudTable from '../../../components/catalogObjectsCrudTable/CrudTable.vue';

import ChannelService from "../../../services/ChannelService.js";
import mediaProductsService from "../../../services/MediaProductsService.js";

const columns = [
    {field: 'id', header: 'ID' },
    {field: 'name', header: 'Название' },
    {field: 'channel.name', header: 'Канал'},
    {field: 'start_time', header: 'Начало трансляции'},
    {field: 'duration', header: 'Длительность'},
];

const formFields = [
    [{type: 'text', name: 'name', label: 'Название' }],
    [{type: 'select', name: 'channel_id', label: 'Канал', optionsService: ChannelService }],
    [
        {type: 'time', name: 'start_time', label: 'Начало трансляции', default: '12:00:00'},
        {type: 'double', name: 'duration', label: 'Длительность трансляции (минут)', step: '0.01', min: '0', default: '0'}
    ],

];

const filters = [
    {
        name: 'Канал',
        type: 'select',
        optionsService: ChannelService,
        queryName: 'channel_id'
    }
];

</script>

<template>
    <GenericCrudTable
        :service="mediaProductsService"
        :columns="columns"
        :formFields="formFields"
        title="Медиа-продукты"
        :filters="filters"
    />
</template>
