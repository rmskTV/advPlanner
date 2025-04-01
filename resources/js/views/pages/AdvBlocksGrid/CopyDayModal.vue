<template>
    <Dialog
        :visible="modelValue"
        @update:visible="$emit('update:modelValue', $event)"
        header="Копировать выходы дня"
        :modal="true"
        :style="{ width: '40vw' }"
        class="copy-modal"
    >
        <div class="p-fluid">
            <div class="mb-4">
                <label>Копировать выходы из:</label>
                <InputText
                    :modelValue="sourceDate"
                    @update:modelValue="$emit('update:sourceDate', $event)"
                    class="w-full"
                    disabled
                />
            </div>

            <div class="flex gap-4 mb-4">
                <div class="w-1/2">
                    <label>Дата начала:</label>
                    <Calendar
                        v-model="copyStartDate"
                        dateFormat="dd.mm.yy"
                        class="w-full"
                        :min="minCopyDate"
                    />
                </div>
                <div class="w-1/2">
                    <label>Дата окончания:</label>
                    <Calendar
                        v-model="copyEndDate"
                        dateFormat="dd.mm.yy"
                        class="w-full"
                        :min="minCopyDate"
                    />
                </div>
            </div>

            <div class="mb-4">
                <label>Дни недели:</label>
                <div class="flex gap-2">
                    <div
                        v-for="(day, index) in daysOfWeek"
                        :key="index"
                        class="flex align-items-center"
                    >
                        <Checkbox
                            v-model="copySelectedDays"
                            :inputId="'copy-' + day.value"
                            name="copyDay"
                            :value="day.value"
                        />
                        <label :for="'copy-' + day.value" class="ml-2">{{ day.label }}</label>
                    </div>
                </div>
            </div>
        </div>

        <template #footer>
            <Button
                label="Отмена"
                @click="$emit('update:modelValue', false)"
                class="p-button-text"
            />
            <Button
                label="Копировать"
                @click="handleSubmit"
                class="p-button-success"
                :disabled="!canCopy"
            />
        </template>
    </Dialog>
</template>

<script setup>
import {computed, ref, watch} from 'vue';

const props = defineProps({
    modelValue: Boolean,
    sourceDate: String,
    daysOfWeek: Array,
    selectedChannel: Object,
    dayToCopyBroadcasts: Array
});

const emit = defineEmits([
    'update:modelValue',
    'update:sourceDate',
    'submit'
]);

const copyStartDate = ref(null);
const copyEndDate = ref(null);
const copySelectedDays = ref([]);
const minCopyDate = ref(new Date());

const canCopy = computed(() => {
    return copyStartDate.value && copyEndDate.value && copySelectedDays.value.length > 0;
});

const handleSubmit = () => {
    emit('submit', {
        startDate: copyStartDate.value,
        endDate: copyEndDate.value,
        days: copySelectedDays.value
    });
};

watch(() => props.modelValue, (newVal) => {
    if (newVal) {
        const nextDay = new Date(props.sourceDate);
        nextDay.setDate(nextDay.getDate() + 1);

        copyStartDate.value = new Date(nextDay);
        copyEndDate.value = new Date(nextDay);
        minCopyDate.value = new Date(nextDay);
        copySelectedDays.value = [...props.daysOfWeek.map(day => day.value)];
    }
});
</script>

<style scoped>
.copy-modal .p-fluid .field {
    margin-bottom: 1rem;
}

.copy-modal .days-checkboxes {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.copy-modal .days-checkboxes .field-checkbox {
    margin-right: 0.5rem;
}
</style>
