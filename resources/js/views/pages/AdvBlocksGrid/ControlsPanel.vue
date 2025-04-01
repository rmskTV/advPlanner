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
    startDate: [Date, String],
    endDate: [Date, String],
    loadingChannels: Boolean,
    errorChannels: String
});

defineEmits([
    'update:selectedChannel',
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
