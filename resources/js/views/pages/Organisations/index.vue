<script setup>
import GenericCrudTable from '../../../components/catalogObjectsCrudTable/CrudTable.vue';
import OrganisationService from "../../../services/OrganisationService.js";
const columns = [
    { field: 'id', header: 'ID' },
    { field: 'name', header: 'Название' },
];

const formFields = [
    [{ type: 'text', name: 'name', label: 'Название' }],
];

</script>

<template>
    <GenericCrudTable
        :service="OrganisationService"
        :columns="columns"
        :formFields="formFields"
        title="Организации"
    />
</template>
