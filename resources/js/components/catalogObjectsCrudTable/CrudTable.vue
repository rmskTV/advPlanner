<template>
    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <Button label="Добавить" icon="pi pi-plus" severity="secondary" class="mr-2" @click="openNew" />
                <Button label="Удалить" icon="pi pi-trash" severity="secondary" @click="confirmDeleteSelected" :disabled="!selectedItems || !selectedItems.length" />
            </template>

            <template #end>
                <Button label="Экспорт" icon="pi pi-upload" severity="secondary" @click="exportCSV($event)" />
            </template>
        </Toolbar>

        <DataTable
            ref="dt"
            v-model:selection="selectedItems"
            :value="items"
            :lazy="true"
            dataKey="id"
            :paginator="true"
            :rows="perPage"
            :filters="filters"
            :totalRecords="totalRecords"
            paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
            :rowsPerPageOptions="[5, 10, 15, 25, 50]"
            currentPageReportTemplate="Записей с {first} по {last}. Всего: {totalRecords} записей"
            @page="onPage"
        >
            <template #header>
                <div class="flex flex-wrap gap-2 items-center justify-between">
                    <h1 class="m-0">{{ title }}</h1>
                    <IconField>
                        <InputIcon>
                            <i class="pi pi-search" />
                        </InputIcon>
                        <InputText v-model="filters['global'].value" placeholder="Search..." />
                    </IconField>
                </div>
            </template>

            <Column selectionMode="multiple" style="width: 3rem" :exportable="false"></Column>
            <slot name="columns"></slot>
            <Column :exportable="false" style="min-width: 12rem">
                <template #body="slotProps">
                    <Button icon="pi pi-pencil" outlined rounded class="mr-2" @click="editItem(slotProps.data)" />
                    <Button icon="pi pi-trash" outlined rounded severity="danger" @click="confirmDeleteItem(slotProps.data)" />
                </template>
            </Column>
        </DataTable>
        <div v-if="loading">Загрузка...</div>
        <div v-if="error">Произошла ошибка при загрузке данных.</div>
    </div>
    <Dialog v-model:visible="itemDialog" :style="{ width: '450px' }" :header="dialogHeader" :modal="true">
        <div class="flex flex-col gap-6">
            <slot name="form">
                <div v-for="field in fields" :key="field.model">
                    <label :for="field.model" class="block font-bold mb-3">{{ field.label }}</label>
                    <InputText
                        :id="field.model"
                        v-model.trim="item[field.model]"
                        :required="field.required"
                        autofocus
                        :invalid="submitted && !item[field.model]"
                        fluid
                    />
                    <small v-if="submitted && !item[field.model]" class="text-red-500">{{ field.label }} обязательное поле.</small>
                </div>
            </slot>
        </div>
        <template #footer>
            <Button label="Отмена" icon="pi pi-times" text @click="hideDialog" />
            <Button label="Сохранить" icon="pi pi-check" @click="saveItem" />
        </template>
    </Dialog>
    <Dialog v-model:visible="deleteItemDialog" :style="{ width: '450px' }" header="Confirm" :modal="true">
        <div class="flex items-center gap-4">
            <i class="pi pi-exclamation-triangle !text-3xl" />
            <span v-if="item"
            >Действительно удалить <b>{{ item.name }}</b
            >?</span
            >
        </div>
        <template #footer>
            <Button label="Нет" icon="pi pi-times" text @click="hideDialog" />
            <Button label="Да" icon="pi pi-check" @click="deleteItem" />
        </template>
    </Dialog>
    <Dialog v-model:visible="deleteItemsDialog" :style="{ width: '450px' }" header="Confirm" :modal="true">
        <div class="flex items-center gap-4">
            <i class="pi pi-exclamation-triangle !text-3xl" />
            <span v-if="item">Вы действительно хотите удалить выделенные организации: <b>{{ selectedItems.map(item => item['name']).join(', ') }}</b>?</span>
        </div>
        <template #footer>
            <Button label="Нет" icon="pi pi-times" text @click="hideDialog" />
            <Button label="Да" icon="pi pi-check" text @click="deleteSelectedItems" />
        </template>
    </Dialog>
</template>

<script setup>
import { FilterMatchMode } from '@primevue/core/api';
import { ref } from 'vue';

const props = defineProps({
    title: {
        type: String,
        required: true,
    },
    fields: {
        type: Array,
        default: () => [],
        required: true,
    },
    dialogHeader: {
        type: String,
        required: true,
    }
})
const filters = ref({
    global: { value: null, matchMode: FilterMatchMode.CONTAINS }
})

defineEmits(['openNew', 'confirmDeleteSelected', 'editItem', 'confirmDeleteItem', 'saveItem', 'hideDialog', 'onPage','exportCSV', 'deleteItem', 'deleteSelectedItems'])

const dt = ref();
const items = ref([]);
const itemDialog = ref(false);
const selectedItems = ref();
const submitted = ref(false);
const loading = ref(false)
const error = ref(null)
const perPage = ref(10);
const totalRecords = ref(0);
const currentPage = ref(1);
const deleteItemDialog = ref(false);
const deleteItemsDialog = ref(false);
const item = ref({});

const onPage = (event) => {
    const page = event.page + 1; // PrimeVue page индексирует с 0
    emits('onPage', event);
}
const openNew = () => {
    emits('openNew')
}
const confirmDeleteSelected = () => {
    emits('confirmDeleteSelected')
}
const editItem = (data) => {
    emits('editItem', data)
}
const confirmDeleteItem = (data) => {
    item.value = data;
    deleteItemDialog.value = true;
}
const saveItem = () => {
    emits('saveItem')
}
const hideDialog = () => {
    emits('hideDialog')
}
const exportCSV = () => {
    dt.value.exportCSV();
    emits('exportCSV')
}
const deleteItem = () => {
    emits('deleteItem',item.value)
}
const deleteSelectedItems = () => {
    emits('deleteSelectedItems')
}

defineExpose({
    dt,
    items,
    itemDialog,
    selectedItems,
    submitted,
    loading,
    error,
    perPage,
    totalRecords,
    currentPage,
    filters
})

</script>
