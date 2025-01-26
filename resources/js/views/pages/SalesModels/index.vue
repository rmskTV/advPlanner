<script setup>
import GenericCrudTable from '../../../components/catalogObjectsCrudTable/CrudTable.vue';
import SalesModelsService from "../../../services/SalesModelsService.js";
import OrganisationService from "../../../services/OrganisationService.js";
const columns = [
    {field: 'id', header: 'ID' },
    {field: 'name', header: 'Название' },
    {field: 'organisation.name', header: 'Организация'},
    {field: 'percent', header: 'Процент от продаж (%)'},
    {field: 'guarantee', header: 'Гарантированный платеж (руб)'},
];

const formFields = [
    [{type: 'text', name: 'name', label: 'Название' }],
    [{type: 'select', name: 'organisation_id', label: 'Организация', optionsService: OrganisationService }],
    [
        {type: 'double', name: 'percent', label: 'Процент', step: '0.01', min: "0", max: "100", default: '0'},
        {type: 'double', name: 'guarantee', label: 'Гарантированный платеж', step: '0.01', min: '0', default: '0'}
    ],

];

</script>

<template>
    <GenericCrudTable
        :service="SalesModelsService"
        :columns="columns"
        :formFields="formFields"
        title="Модели продаж"
    />
</template>
