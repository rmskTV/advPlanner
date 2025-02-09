// useCrudTable.js
import {onMounted, ref, watch} from 'vue';
import { FilterMatchMode } from '@primevue/core/api';
import { useToast } from 'primevue/usetoast';


export function useCrudTable(service, initialFilters = []) {
    const toast = useToast();
    const item = ref({});
    const submitted = ref(false);
    const itemDialog = ref(false);
    const deleteItemDialog = ref(false);
    const deleteItemsDialog = ref(false);
    const items = ref([]);
    const totalRecords = ref(0);
    const loading = ref(false);
    const error = ref(null);
    const perPage = ref(10);
    const currentPage = ref(1);
    const selectedItems = ref();
    const fieldOptions = ref({}); // Ref для хранения опций для полей
    const filtersValues = ref({}); // Новое свойство для активных фильтров

    const loadData = async (page = 1, perPageValue = perPage.value) => {
        loading.value = true;
        error.value = null;
        try {
            const params = {
                page: page.v,
                per_page: perPageValue,
                ...filtersValues.value // Добавляем активные фильтры в запрос
            };
            const data = await service.List(params);
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

    const loadFieldOptions = async (formFields) => {
        const fields = Array.isArray(formFields[0]) ? formFields.flat() : formFields;
        for (const field of fields) {
            if (field.type === 'select' && field.optionsService) {
                try {
                    let optionsResponse;
                    if (field.cascadeDependency) {
                        // Если селект зависит от другого поля, загружаем опции только если выбрано зависимое поле
                        const dependencyValue = item.value[field.cascadeDependency];
                        if (dependencyValue) {
                            optionsResponse = await field.optionsService.List({ [field.cascadeDependency]: dependencyValue });
                        } else {
                            optionsResponse = await field.optionsService.List();
                        }
                    } else {
                        // Обычная загрузка опций
                        optionsResponse = await field.optionsService.List();
                    }


                    const options = optionsResponse.data.map((option) => ({
                        label: option.name,
                        value: option.id,
                    }));
                    // Добавляем опцию "Не выбрано"
                    options.unshift({label: 'Не выбрано', value: null});
                    fieldOptions.value[field.name] = options

                } catch (err) {
                    toast.add({
                        severity: 'error',
                        summary: `Ошибка загрузки опций для поля: ${field.label || field.name}`,
                        detail: 'Не удалось загрузить список опций',
                        life: 3000
                    });
                }
            } else {
                fieldOptions.value[field.name] = [];
            }
        }
    }



    watch(perPage, () => {
        loadData(1)
    })
    onMounted(async () => {
        loadData(1);
    });

    const setupCascadeWatchers = (formFields, filters) => {
        const fields = Array.isArray(formFields[0]) ? formFields.flat() : formFields;

        fields.forEach(field => {
            if (field.type === 'select' && field.cascade) {
                // Находим зависимое поле
                const dependentField = fields.find(f => f.cascadeDependency === field.name);

                if (dependentField) {
                    watch(
                        () => item.value[field.name], // Отслеживаем изменение значения поля с cascade
                        async (newValue) => {
                            // Когда значение изменяется, перезагружаем опции только для зависимого поля
                            await loadFieldOptions([dependentField]); // Передаем массив с одним зависимым полем
                        }
                    );
                }
            }
        });
    };

    const openNew = async (formFields) => {
        item.value = {};
        submitted.value = false;
        await loadFieldOptions(formFields);
        itemDialog.value = true;
    }
    const hideDialog = () => {
        itemDialog.value = false;
        submitted.value = false;
    };
    const openDialog = async (itemSlot = null, formFields) => {
        if (itemSlot != null) {
            item.value = {...itemSlot};
        } else {
            item.value = {};
        }
        submitted.value = false;
        await loadFieldOptions(formFields);
        itemDialog.value = true;
    };

    const sendDeleteRequest = async () => {
        try {
            const data = await service.Delete(item.value.id);
            if (data === 1) {
                toast.add({
                    severity: 'success',
                    summary: 'Удалено',
                    detail: 'Успешно удалена запись',
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
        } catch (err) {
            toast.add({
                severity: 'error',
                summary: 'Ошибка запроса к API',
                detail: 'Запрос не выполнен',
                life: 3000
            });
        }
        item.value = {};
    }
    const sendUpdateRequest = async () => {
        try {
            const data = await service.Update(item.value);
            if (data.id === item.value.id) {
                toast.add({
                    severity: 'success',
                    summary: 'Обновлено',
                    detail: 'Успешно обновлена запись ',
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
    const sendCreateRequest = async () => {
        try {
            const data = await service.Create(item.value);
            if (data.id !== null) {
                toast.add({
                    severity: 'success',
                    summary: 'Добавлено',
                    detail: 'Успешно добавлена запись',
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
    };

    const saveItem = async () => {
        submitted.value = true;
        if (item?.value.name?.trim()) {
            if (item.value.id == null) {
                await sendCreateRequest(loadData);
            } else {
                await sendUpdateRequest(loadData);
            }
            await loadData(currentPage.value, perPage.value);
            itemDialog.value = false;
        }
    };
    const deleteItem = async () => {
        await sendDeleteRequest();
        deleteItemDialog.value = false;
        await loadData(currentPage.value, perPage.value);
    }

    const deleteSelectedItems = async () => {
        for (const element of selectedItems.value) {
            item.value = element;
            await sendDeleteRequest();
        }
        deleteItemsDialog.value = false;
        selectedItems.value = null;
        await loadData(currentPage.value, perPage.value);
    }

    const applyFilter = (filterName, value) => {
        filtersValues[filterName] = value;
        loadData(currentPage.value, perPage.value); // Перезагружаем данные с новыми фильтрами
    };


    return {
        item,
        submitted,
        itemDialog,
        openNew,
        hideDialog,
        openDialog,
        sendDeleteRequest,
        saveItem,
        deleteItemDialog,
        deleteItemsDialog,
        deleteItem,
        items,
        totalRecords,
        loading,
        error,
        perPage,
        currentPage,
        filtersValues,
        loadData,
        selectedItems,
        fieldOptions,
        deleteSelectedItems,
        applyFilter,
        loadFieldOptions,
        setupCascadeWatchers
    };
}
