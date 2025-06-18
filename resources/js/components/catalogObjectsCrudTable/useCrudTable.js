import {onMounted, ref, watch} from 'vue';
import { FilterMatchMode } from '@primevue/core/api';
import { useToast } from 'primevue/usetoast';

export function useCrudTable(service, initialFilters = [], parentFilter = { name: null, value: null }) {
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
    const fieldOptions = ref({});
    const filtersValues = ref({});

    const loadData = async (page = 1, perPageValue = perPage.value) => {
        loading.value = true;
        error.value = null;
        try {
            const params = {
                page: page.valueOf(),
                per_page: perPageValue,
                ...filtersValues.value,
                [parentFilter.name]: parentFilter.value,
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
                    fieldOptions.value[field.name] = options;
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
    };

    const hasFileFields = (formFields) => {
        const fields = Array.isArray(formFields[0]) ? formFields.flat() : formFields;
        return fields.some(field => field.type === 'file');
    };

    const prepareFormData = (data) => {
        const formData = new FormData();
        for (const key in data) {
            if (data[key] instanceof File) {
                formData.append(key, data[key]);
            } else if (data[key] !== null && data[key] !== undefined) {
                formData.append(key, data[key]);
            }
        }
        return formData;
    };

    const sendCreateRequest = async (formFields) => {
        try {
            let data;
            if (hasFileFields(formFields)) {
                const formData = prepareFormData(item.value);
                data = await service.CreateWithFiles(formData);
            } else {
                data = await service.Create(item.value);
            }

            if (data.id) {
                toast.add({
                    severity: 'success',
                    summary: 'Добавлено',
                    detail: 'Успешно добавлена запись',
                    life: 3000
                });
                return true;
            } else {
                toast.add({
                    severity: 'error',
                    summary: 'Неизвестный ответ API',
                    detail: data.data,
                    life: 3000
                });
                return false;
            }
        } catch (err) {
            toast.add({
                severity: 'error',
                summary: 'Ошибка запроса к API',
                detail: err.message || 'Запрос не выполнен',
                life: 3000
            });
            return false;
        }
    };

    const sendUpdateRequest = async (formFields) => {
        try {
            let data;
            if (hasFileFields(formFields)) {
                const formData = prepareFormData(item.value);
                data = await service.UpdateWithFiles(item.value.id, formData);
            } else {
                data = await service.Update(item.value);
            }

            if (data.id) {
                toast.add({
                    severity: 'success',
                    summary: 'Обновлено',
                    detail: 'Успешно обновлена запись',
                    life: 3000
                });
                return true;
            } else {
                toast.add({
                    severity: 'error',
                    summary: 'Неизвестный ответ API',
                    detail: data.data,
                    life: 3000
                });
                return false;
            }
        } catch (err) {
            toast.add({
                severity: 'error',
                summary: 'Ошибка запроса к API',
                detail: err.message || 'Запрос не выполнен',
                life: 3000
            });
            return false;
        }
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
                return true;
            } else {
                toast.add({
                    severity: 'error',
                    summary: 'Неизвестный ответ API',
                    detail: data.data,
                    life: 3000
                });
                return false;
            }
        } catch (err) {
            toast.add({
                severity: 'error',
                summary: 'Ошибка запроса к API',
                detail: err.message || 'Запрос не выполнен',
                life: 3000
            });
            return false;
        }
    };

    const saveItem = async (formFields) => {
        submitted.value = true;

        // Проверка обязательных полей
        const fields = Array.isArray(formFields[0]) ? formFields.flat() : formFields;
        const hasEmptyRequiredFields = fields.some(field =>
            field.required &&
            (item.value[field.name] === null ||
                item.value[field.name] === undefined ||
                item.value[field.name] === '')
        );

        if (hasEmptyRequiredFields) {
            toast.add({
                severity: 'error',
                summary: 'Ошибка валидации',
                detail: 'Заполните все обязательные поля',
                life: 3000
            });
            return;
        }

        let success;
        if (item.value.id == null) {
            success = await sendCreateRequest(formFields);
        } else {
            success = await sendUpdateRequest(formFields);
        }

        if (success) {
            await loadData(currentPage.value, perPage.value);
            itemDialog.value = false;
            item.value = {};
        }
    };

    const deleteItem = async () => {
        const success = await sendDeleteRequest();
        if (success) {
            deleteItemDialog.value = false;
            await loadData(currentPage.value, perPage.value);
            item.value = {};
        }
    };

    const deleteSelectedItems = async () => {
        let allSuccess = true;
        for (const element of selectedItems.value) {
            item.value = element;
            const success = await sendDeleteRequest();
            if (!success) allSuccess = false;
        }

        if (allSuccess) {
            deleteItemsDialog.value = false;
            selectedItems.value = null;
            await loadData(currentPage.value, perPage.value);
        }
    };

    watch(perPage, () => {
        loadData(1);
    });

    onMounted(async () => {
        loadData(1);
    });

    const setupCascadeWatchers = (formFields) => {
        const fields = Array.isArray(formFields[0]) ? formFields.flat() : formFields;

        fields.forEach(field => {
            if (field.type === 'select' && field.cascade) {
                const dependentField = fields.find(f => f.cascadeDependency === field.name);
                if (dependentField) {
                    watch(
                        () => item.value[field.name],
                        async (newValue) => {
                            await loadFieldOptions([dependentField]);
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
    };

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

    const applyFilter = (filterName, value) => {
        filtersValues.value[filterName] = value;
        loadData(currentPage.value, perPage.value);
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
