<script setup>
import OrganisationService from '../../../service/OrganisationService';
let Service = OrganisationService;
import { FilterMatchMode } from '@primevue/core/api';
import { useToast } from 'primevue/usetoast';
import { onMounted, ref, watch } from 'vue';

const toast = useToast();
const dt = ref();
const items = ref([]);
const itemDialog = ref(false);
const deleteItemDialog = ref(false);
const deleteItemsDialog = ref(false);
const item = ref({});
const selectedItems = ref();
const filters = ref({
    global: { value: null, matchMode: FilterMatchMode.CONTAINS }
});
const submitted = ref(false);

const loading = ref(false);
const error = ref(null);

const perPage = ref(10);
const totalRecords = ref(0);
const currentPage = ref(1);

const onPage = (event) => {
    const page = event.page + 1; // PrimeVue page индексирует с 0
    loadItems(page, event.rows);
};

watch(perPage, () => {
    loadItems(1)
})
onMounted(() => {
    loadItems(1)
});

const loadItems = async (page = 1, perPageValue = perPage.value) => {
    loading.value = true;
    error.value = null;
    try {
        const data = await Service.List(page, perPageValue);
        items.value = data.data;
        totalRecords.value = data.total;
        currentPage.value = data.current_page;
        perPage.value = data.per_page;
        loading.value = false;
    } catch (err) {
        error.value = err.message;
        loading.value = false;
        toast.add({
            severity: 'error',
            summary: 'Ошибка запроса к API',
            detail: 'Данные не загружены',
            life: 3000
        });
    }
};

async function sendDeleteRequest() {
    try {
        const data = await Service.Delete(item.value.id);
        if (data === 1) {
            toast.add({
                severity: 'success',
                summary: 'Удалено',
                detail: 'Успешно удалена организация ' + item.value.name,
                life: 3000
            });
        } else {
            toast.add({
                severity: 'error',
                summary: 'Неизвестный ответ API',
                detail: data.data,
                life: 3000
            });
        }
        item.value = {};
    } catch (err) {
        toast.add({
            severity: 'error',
            summary: 'Ошибка запроса к API',
            detail: 'Запрос не выполнен',
            life: 3000
        });
    }
}
async function sendUpdateRequest() {
    try {
        const data = await Service.Update(item.value);
        if (data.id === item.value.id) {
            toast.add({
                severity: 'success',
                summary: 'Обновлено',
                detail: 'Успешно обновлена организация ' + item.value.name,
                life: 3000
            });
        } else {
            toast.add({
                severity: 'error',
                summary: 'Неизвестный ответ API',
                detail: data.data,
                life: 3000
            });
        }
        item.value = {};
    } catch (err) {
        toast.add({
            severity: 'error',
            summary: 'Ошибка запроса к API',
            detail: 'Запрос не выполнен',
            life: 3000
        });
    }
}

async function sendCreateRequest() {
    try {
        const data = await Service.Create(item.value);
        if (data.id !== null) {
            toast.add({
                severity: 'success',
                summary: 'Добавлено',
                detail: 'Успешно добавлена организация ' + item.value.name,
                life: 3000
            });
        } else {
            toast.add({
                severity: 'error',
                summary: 'Неизвестный ответ API',
                detail: data.data,
                life: 3000
            });
        }
        item.value = {};
    } catch (err) {
        toast.add({
            severity: 'error',
            summary: 'Ошибка запроса к API',
            detail: 'Запрос не выполнен ' + err,
            life: 3000
        });
    }
}

async function deleteItem() {
    await sendDeleteRequest();
    await loadItems(currentPage.value, perPage.value);
    deleteItemDialog.value = false;
}

function confirmDeleteItem(slotItem) {
    item.value = slotItem;
    deleteItemDialog.value = true;
}

function confirmDeleteSelected() {
    deleteItemsDialog.value = true;
}

async function deleteSelectedItems() {
    for (const element of selectedItems.value) {
        item.value = element;
        await sendDeleteRequest();
    }
    deleteItemsDialog.value = false;
    selectedItems.value = null;
    await loadItems(currentPage.value, perPage.value);
}

function editItem(itemSlot) {
    item.value = { ...itemSlot };
    itemDialog.value = true;
}

async function saveItem() {
    submitted.value = true;
    if (item?.value.name?.trim()) {
        if (item.value.id == null) {
            await sendCreateRequest();
            await loadItems(currentPage.value, perPage.value);

        } else {
            await sendUpdateRequest();
            await loadItems(currentPage.value, perPage.value);
        }

        itemDialog.value = false;
        item.value = {};
    }
}

function openNew() {
    item.value = {};
    submitted.value = false;
    itemDialog.value = true;
}

function hideDialog() {
    itemDialog.value = false;
    submitted.value = false;
}

function exportCSV() {
    dt.value.exportCSV();
}

</script>

<template>
    <div>
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
                        <h1 class="m-0">Управление организациями</h1>
                        <IconField>
                            <InputIcon>
                                <i class="pi pi-search" />
                            </InputIcon>
                            <InputText v-model="filters['global'].value" placeholder="Search..." />
                        </IconField>
                    </div>
                </template>

                <Column selectionMode="multiple" style="width: 3rem" :exportable="false"></Column>
                <Column field="id" header="ID" sortable style="min-width: 12rem"></Column>
                <Column field="name" header="Название" sortable style="min-width: 16rem"></Column>

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

        <Dialog v-model:visible="itemDialog" :style="{ width: '450px' }" header="Организация" :modal="true">
            <div class="flex flex-col gap-6">
                <div>
                    <label for="name" class="block font-bold mb-3">Название</label>
                    <InputText id="name" v-model.trim="item.name" required="true" autofocus :invalid="submitted && !item.name" fluid />
                    <small v-if="submitted && !item.name" class="text-red-500">Название - обязательный атрибут.</small>
                </div>
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
                <Button label="Нет" icon="pi pi-times" text @click="deleteItemDialog = false" />
                <Button label="Да" icon="pi pi-check" @click="deleteItem" />
            </template>
        </Dialog>

        <Dialog v-model:visible="deleteItemsDialog" :style="{ width: '450px' }" header="Confirm" :modal="true">
            <div class="flex items-center gap-4">
                <i class="pi pi-exclamation-triangle !text-3xl" />
                <span v-if="item">Вы действительно хотите удалить выделенные организации: <b>{{ selectedItems.map(item => item['name']).join(', ') }}</b>?</span>
            </div>
            <template #footer>
                <Button label="Нет" icon="pi pi-times" text @click="deleteItemsDialog = false" />
                <Button label="Да" icon="pi pi-check" text @click="deleteSelectedItems" />
            </template>
        </Dialog>
    </div>
</template>
