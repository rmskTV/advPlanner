<script setup>
import GenericCrudTable from '../../../components/catalogObjectsCrudTable/CrudTable.vue';
import ChannelService from "../../../services/ChannelService.js";
import OrganisationService from "../../../services/OrganisationService.js";

const columns = [
    {field: 'id', header: 'ID'},
    {field: 'name', header: 'Название'},
    {field: 'organisation.name', header: 'Организация'},
];

const formFields = [
    {type: 'text', name: 'name', label: 'Название'},
    {type: 'select', name: 'organisation_id', label: 'Организация', optionsService: OrganisationService },
];

</script>

<template>
    <GenericCrudTable
        :service="ChannelService"
        :columns="columns"
        :formFields="formFields"
        title="Каналы"
    />
</template>
