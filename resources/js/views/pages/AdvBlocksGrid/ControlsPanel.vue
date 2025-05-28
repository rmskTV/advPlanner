<template>
    <div class="controls-section p-4">
        <div class="flex gap-4">
            <div class="w-1/2">
                <label class="block mb-2 font-bold">Канал:</label>
                <Dropdown
                    :modelValue="selectedChannel"
                    :options="channels"
                    optionLabel="name"
                    placeholder="Выберите канал"
                    class="w-full"
                    :loading="loadingChannels"
                    :disabled="loadingChannels"
                    @update:modelValue="$emit('update:selectedChannel', $event)"
                />
                <small v-if="errorChannels" class="text-red-500">{{ errorChannels }}</small>
            </div>

            <div class="w-1/3">
                <label class="block mb-2 font-bold">Медиапродукт:</label>
                <Dropdown
                    :modelValue="selectedMediaProduct"
                    :options="mediaProducts"
                    optionLabel="name"
                    placeholder="Выберите медиапродукт"
                    class="w-full"
                    :loading="mediaProductsLoading.isLoading"
                    :disabled="mediaProductsLoading.isLoading"
                    @update:modelValue="$emit('update:selectedMediaProduct', $event)"
                >
                    <template #option="slotProps">
                        <div v-if="slotProps.option.id === null" class="font-semibold">
                            {{ slotProps.option.name }}
                        </div>
                        <div v-else>
                            {{ slotProps.option.name }}
                            <span v-if="mediaProductsLoading.isLoading" class="text-xs opacity-50 ml-2">
                        Загрузка... ({{ mediaProductsLoading.loaded }}/{{ mediaProductsLoading.total }})
                    </span>
                        </div>
                    </template>
                </Dropdown>
            </div>

            <div class="w-1/2">
                <label class="block mb-2 font-bold">Период:</label>
                <div class="flex gap-2">
                    <Calendar
                        :modelValue="startDate"
                        placeholder="Начало периода"
                        dateFormat="dd.mm.yy"
                        class="w-1/2"
                        @update:modelValue="$emit('update:startDate', $event)"
                    />
                    <Calendar
                        :modelValue="endDate"
                        placeholder="Конец периода"
                        dateFormat="dd.mm.yy"
                        class="w-1/2"
                        @update:modelValue="$emit('update:endDate', $event)"
                    />
                </div>
            </div>
        </div>
        <Button
            label="Добавить выходы"
            @click="$emit('openAddModal')"
            class="mt-4"
        />
    </div>
</template>
<script setup>
defineProps({
    channels: Array,
    selectedChannel: Object,
    mediaProducts: Array,
    selectedMediaProduct: Object,
    startDate: [Date, String],
    endDate: [Date, String],
    loadingChannels: Boolean,
    errorChannels: String,
    mediaProductsLoading: {  // Добавляем новый prop
        type: Object,
        default: () => ({
            isLoading: false,
            currentPage: 0,
            totalPages: 1,
            loaded: 0,
            total: 0
        })
    }
});

defineEmits([
    'update:selectedChannel',
    'update:selectedMediaProduct',
    'update:startDate',
    'update:endDate',
    'openAddModal'
]);
</script>

<style scoped>
.controls-section {
    background: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
}
</style>
