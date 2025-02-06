<script setup>
import GenericCrudTable from '../../../components/catalogObjectsCrudTable/CrudTable.vue';
import AdvBlockTypeService from "../../../services/AdvBlockTypeService.js";
import AccountingUnitsService from "../../../services/AccountingUnitsService.js";

const columns = [
    {field: 'id', header: 'ID' },
    {field: 'name', header: 'Название' },
    {field: 'accounting_unit_label', header: 'Единица учета'},
    {field: 'is_with_exact_time', header: 'Точное время выхода'},
];

const formFields = [
    [{type: 'text', name: 'name', label: 'Название' }],
    [
        {type: 'select', name: 'accounting_unit', label: 'Единица учета', optionsService: AccountingUnitsService},
        {type: 'checkbox', name: 'is_with_exact_time', label: 'Точное время выхода', default: '0'}
    ],

];

</script>

<template>
    <GenericCrudTable
        :service="AdvBlockTypeService"
        :columns="columns"
        :formFields="formFields"
        title="Типы рекламных блоков"
    />
</template>
