// GenericCrudTable.vue
<script setup>
import { useCrudTable } from './useCrudTable.js';
import {onMounted, ref, watch} from 'vue';

const props = defineProps({
    service: { type: Object, required: true },
    columns: { type: Array, required: true },
    formFields: { type: Array, required: true },
    title: { type: String, default: '' },
    filters: { type: Array, default: () => [] },
});


onMounted(async () => {
    document.title = props['title'];
    loadFieldOptions(props['formFields']);
    setupCascadeWatchers(props['formFields'])
});

const dt = ref();

const {selectedItems,  items, totalRecords, loading, error, perPage, currentPage, filtersValues, loadData,
    item, submitted, itemDialog, openNew, hideDialog, openDialog, sendDeleteRequest,
    saveItem, deleteItemDialog, deleteItemsDialog, deleteItem, deleteSelectedItems, fieldOptions, applyFilter, loadFieldOptions, setupCascadeWatchers } = useCrudTable(props.service, props.filters);


const onPage = (event) => {
    const page = event.page + 1;
    loadData(page, event.rows);
};

const setInitialValues = () => {
    if(itemDialog.value) {
        const fields = Array.isArray(props.formFields[0]) ? props.formFields.flat() : props.formFields;
        fields.forEach(field => {
            if (field.type === 'double' && (item.value[field.name] === null || item.value[field.name] === undefined)) {
                item.value[field.name] = field.default || 0;
            }
            if (field.type === 'time' && (item.value[field.name] === null || item.value[field.name] === undefined)) {
                item.value[field.name] = field.default || '12:00:00';
            }
        })

    }
}

watch(itemDialog, (newValue) => {
    if(newValue){
        setInitialValues()
    }
})


function confirmDeleteSelected() {
    deleteItemsDialog.value = true;
}

function confirmDeleteItem(slotItem) {
    item.value = slotItem;
    deleteItemDialog.value = true;
}

function editItem(itemSlot) {
    openDialog(itemSlot, props.formFields)
}

function exportCSV() {
    dt.value.exportCSV();
}

const calculateFieldWidth = (fieldCount) => {
    switch (fieldCount) {
        case 1:
            return 'w-full';
        case 2:
            return 'w-1/2';
        case 3:
            return 'w-1/3';
        case 4:
            return 'w-1/4';
        default:
            return 'w-full';
    }
}

const formatBooleanField = (value) => {
    if (typeof value === 'boolean') {
        return value ? 'Да' : 'Нет';
    }

    if (value === 1) {
        return 'Да';
    }

    if (value === 0) {
        return 'Нет';
    }

    return value;

};

const getValueByPath = (obj, path) => {
    if (!path) return null;

    const parts = path.split('.');
    let result = obj;

    for (const part of parts) {
        if (result && typeof result === 'object' && part in result) {
            result = result[part];
        } else {
            return null;
        }
    }

    return result;
};

</script>

<template>
    <div>
        <div class="card">
            <Toolbar class="mb-6">
                <template #start>
                    <Button label="Добавить" icon="pi pi-plus" severity="secondary" class="mr-2" @click="openNew(props.formFields)" />
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
                        <!-- Фильтры -->
                        <div class="flex gap-4 mb-4">
                            <div v-for="filter in props.filters" :key="filter.name">
                                <label :for="filter.name" class="block font-bold mb-2">{{ filter.name }}</label>
                                <Dropdown
                                    :id="filter.name"
                                    v-model="filtersValues[filter.queryName]"
                                    :options="fieldOptions[filter.queryName]"
                                    optionLabel="label"
                                    optionValue="value"
                                    @change="applyFilter(filter.queryName, $event.value)"
                                    class="w-full"
                                />
                            </div>
                        </div>

<!--                        <IconField>-->
<!--                            <InputIcon>-->
<!--                                <i class="pi pi-search" />-->
<!--                            </InputIcon>-->
<!--                            <InputText v-model="filters['global'].value" placeholder="Search..." />-->
<!--                        </IconField>-->

                    </div>
                </template>
                <Column selectionMode="multiple" style="width: 3rem" :exportable="false"></Column>
                <Column v-for="col in columns" :key="col.field" :field="col.field" :header="col.header" sortable style="min-width: 12rem">
                    <template #body="slotProps">
                        <template v-if="col.field.startsWith('is_')">
                            {{ formatBooleanField(slotProps.data[col.field]) }}
                        </template>
                        <template v-else>
                            {{ getValueByPath(slotProps.data, col.field) }}

                        </template>
                    </template>
                </Column>
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



        <Dialog
            v-model:visible="itemDialog"
            :style="{ width: '600px' }"
            :header="title"
            :modal="true"
        >
            <div class="flex flex-col gap-6">
                <div v-for="row in formFields" :key="row">
                    <div class="flex gap-6">
                        <div
                            v-for="field in row"
                            :key="field.name"
                            :class="calculateFieldWidth(row.length)"
                        >
                            <label :for="field.name" class="block font-bold mb-3">{{ field.label }}</label>

                            <!-- Поле для времени -->
                            <template v-if="field.type === 'time'">
                                <InputMask
                                    :id="field.name"
                                    v-model="item[field.name]"
                                    mask="99:99:99"
                                    placeholder="HH:MM:SS"
                                    slotChar="0"
                                    :defaultValue="item[field.name] || field.default"
                                    class="w-full"
                                />
                            </template>

                            <!-- Селект -->
                            <template v-else-if="field.type === 'select'">
                                <Dropdown
                                    :id="field.name"
                                    v-model="item[field.name]"
                                    :options="fieldOptions[field.name]"
                                    optionLabel="label"
                                    optionValue="value"
                                    :invalid="submitted && !item[field.name]"
                                    class="w-full"
                                />
                                <small v-if="submitted && !item[field.name]" class="text-red-500">{{ field.label }} - обязательный атрибут.</small>
                            </template>

                            <!-- Дабл -->
                            <template v-else-if="field.type === 'double'">
                                <InputNumber
                                    :id="field.name"
                                    v-model="item[field.name]"
                                    :step="field.step"
                                    :min="field.min"
                                    :max="field.max"
                                    :maxfractiondigits="2"
                                    :minFractionDigits="2"
                                    :maxFractionDigits="2"
                                    :default-value="item[field.name] || field.default"
                                    mode="decimal"
                                    :invalid="submitted && !item[field.name]"
                                    class="w-full"
                                />
                                <small
                                    v-if="submitted && !item[field.name]"
                                    class="text-red-500"
                                >{{ field.label }} - обязательный атрибут.</small>
                            </template>

                            <!-- Чекбокс -->
                            <template v-else-if="field.type === 'checkbox'">
                                <Checkbox
                                    :id="field.name"
                                    v-model="item[field.name]"
                                    :binary="true"
                                    :trueValue="1"
                                    :falseValue="0"
                                />
                            </template>

                            <template v-else>
                                <InputText
                                    :id="field.name"
                                    v-model.trim="item[field.name]"
                                    required="true"
                                    autofocus
                                    :invalid="submitted && !item[field.name]"
                                    fluid
                                />
                                <small
                                    v-if="submitted && !item[field.name]"
                                    class="text-red-500"
                                >{{ field.label }} - обязательный атрибут.</small
                                >
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <template #footer>
                <Button label="Отмена" icon="pi pi-times" text @click="hideDialog" />
                <Button label="Сохранить" icon="pi pi-check" @click="saveItem(props.formFields)" />
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
                <span v-if="item">Вы действительно хотите удалить: <b>{{ selectedItems.map(item => item['name']).join(', ') }}</b>?</span>
            </div>
            <template #footer>
                <Button label="Нет" icon="pi pi-times" text @click="deleteItemsDialog = false" />
                <Button label="Да" icon="pi pi-check" text @click="deleteSelectedItems" />
            </template>
        </Dialog>
    </div>
</template>
