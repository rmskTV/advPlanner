<script setup>
import GenericCrudTable from '../../../components/catalogObjectsCrudTable/CrudTable.vue';
import ExchangeConnectorService from "../../../services/ExchangeConnectorService.js";
import AccountingUnitsService from "../../../services/AccountingUnitsService.js";

const columns = [
    {field: 'id', header: 'ID' },
    {field: 'own_base_name', header: 'Название локальной базы' },
    {field: 'foreign_base_name', header: 'Название удаленной базы'},
];

const formFields = [
    [{type: 'file', name: 'config', label: 'XML-файл с конфигурацией', required: true }],

];

</script>

<template>
    <GenericCrudTable
        :service="ExchangeConnectorService"
        :columns="columns"
        :formFields="formFields"
        title="Коннекторы обмена"
    />
</template>
