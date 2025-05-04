<template>
    <Dialog
        :visible="modelValue"
        @update:visible="$emit('update:modelValue', $event)"
        header="Добавить выходы рекламных блоков"
        :modal="true"
        :style="{ width: '40vw' }"
    >
        <div class="p-fluid">
            <div class="flex gap-4 mb-4">
                <div class="w-2/3">
                    <label for="advBlock">Рекламный блок:</label>
                    <Dropdown
                        v-model="selectedAdvBlock"
                        :options="advBlocks"
                        optionLabel="name"
                        placeholder="Выберите рекламный блок"
                        class="w-full"
                    />
                </div>
                <div class="w-1/3">
                    <label for="size">Размер:</label>
                    <InputNumber
                        v-model="size"
                        mode="decimal"
                        :min="0"
                        class="w-full"
                    />
                </div>
            </div>

            <div class="flex gap-4 mb-4">
                <div class="w-1/3">
                    <label for="startDate">Дата начала:</label>
                    <Calendar
                        v-model="startDate"
                        dateFormat="dd.mm.yy"
                        class="w-full"
                    />
                </div>
                <div class="w-1/3">
                    <label for="endDate">Дата окончания:</label>
                    <Calendar
                        v-model="endDate"
                        dateFormat="dd.mm.yy"
                        class="w-full"
                    />
                </div>
                <div class="w-1/3">
                    <label for="time">Время (hh:mm:ss):</label>
                    <InputMask
                        v-model="time"
                        mask="99:99:99"
                        placeholder="hh:mm:ss"
                        class="w-full"
                    />
                </div>
            </div>

            <div class="flex gap-4 mb-4">
                <div class="w-3/4">
                    <label>Дни недели:</label>
                    <div class="flex gap-2">
                        <div
                            v-for="(day, index) in daysOfWeek"
                            :key="index"
                            class="flex align-items-center"
                        >
                            <Checkbox
                                v-model="selectedDays"
                                :inputId="day.value"
                                name="day"
                                :value="day.value"
                            />
                            <label :for="day.value" class="ml-2">{{ day.label }}</label>
                        </div>
                    </div>
                </div>
                <div class="w-1/4 flex align-items-end">
                    <div class="flex gap-2">
                        <Button
                            label="Выбрать все"
                            @click="selectAllDays"
                            class="p-button-secondary w-full"
                        />
                        <Button
                            label="Снять все"
                            @click="deselectAllDays"
                            class="p-button-secondary w-full"
                        />
                    </div>
                </div>
            </div>
        </div>

        <template #footer>
            <Button
                label="Отмена"
                @click="modelValue = false"
                class="p-button-text"
            />
            <Button
                label="Добавить"
                @click="handleSubmit"
                class="p-button-success"
            />
        </template>
    </Dialog>
</template>

<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    modelValue: Boolean,
    advBlocks: Array,
    daysOfWeek: Array,
    selectedChannel: Object
});

const emit = defineEmits(['update:modelValue', 'submit']);

const selectedAdvBlock = ref(null);
const size = ref(0);
const time = ref('');
const startDate = ref(null);
const endDate = ref(null);
const selectedDays = ref([]);

const selectAllDays = () => {
    selectedDays.value = props.daysOfWeek.map(day => day.value);
};

const deselectAllDays = () => {
    selectedDays.value = [];
};

const handleSubmit = () => {
    emit('submit', {
        advBlock: selectedAdvBlock.value,
        size: size.value,
        time: time.value,
        startDate: startDate.value,
        endDate: endDate.value,
        days: selectedDays.value
    });
};

watch(() => props.modelValue, (newVal) => {
    if (!newVal) {
        // Сброс формы при закрытии
        selectedAdvBlock.value = null;
        size.value = 0;
        time.value = '';
        startDate.value = null;
        endDate.value = null;
        selectedDays.value = [];
    }
});

watch(selectedAdvBlock, (newBlock) => {
    if (newBlock && newBlock.size !== undefined) {
        size.value = newBlock.size;
    }
});

</script>
