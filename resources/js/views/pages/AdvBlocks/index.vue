<script setup>
import GenericCrudTable from '../../../components/catalogObjectsCrudTable/CrudTable.vue';
import AdvBlockTypeService from "../../../services/AdvBlockTypeService.js";
import ChannelService from "../../../services/ChannelService.js";
import MediaProductsService from "../../../services/MediaProductsService.js";
import AdvBlockService from "../../../services/AdvBlockService.js";

const columns = [
    {field: 'id', header: 'ID' },
    {field: 'name', header: 'Название' },
    {field: 'adv_block_type.name', header: 'Тип блока'},
    {field: 'media_product.name', header: 'Медиапродукт'},
    {field: 'channel.name', header: 'Канал'},
];

const formFields = [
    [
        { type: 'select', name: 'channel_id', label: 'Канал', optionsService: ChannelService, cascade: 'media_product_id' },
        { type: 'select', name: 'media_product_id', label: 'Медиапродукт', optionsService: MediaProductsService, cascadeDependency: 'channel_id' },
    ],
    [{type: 'text', name: 'name', label: 'Название' }],
    [
        {type: 'select', name: 'adv_block_type_id', label: 'Тип блока', optionsService: AdvBlockTypeService},
        {type: 'checkbox', name: 'is_only_for_package', label: 'Блок только для пакетных размещений', default: '0'}
    ],
    [{type: 'double', name: 'size', label: 'Размер' }],
    [{type: 'text', name: 'comment', label: 'Комментарий' }],

];

const filters = [
    {
        name: 'Канал',
        type: 'select',
        optionsService: ChannelService,
        queryName: 'channel_id',
        cascade: 'media_product_id'
    },{
        name: 'Тип блока',
        type: 'select',
        optionsService: AdvBlockTypeService,
        queryName: 'adv_block_type_id'
    },{
        name: 'МедиаПродукт',
        type: 'select',
        optionsService: MediaProductsService,
        queryName: 'media_product_id',
        cascadeDependency: 'channel_id'
    }
];
</script>

<template>
    <GenericCrudTable
        :service="AdvBlockService"
        :columns="columns"
        :formFields="formFields"
        title="Рекламные блоки"
        :filters="filters"
    />
</template>
