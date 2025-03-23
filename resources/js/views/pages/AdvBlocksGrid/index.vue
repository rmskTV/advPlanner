<template>
    <div class="ad-grid-container">
        <!-- Верхняя часть с выбором параметров -->
        <div class="controls-section p-4">
            <div class="flex gap-4">
                <div class="w-1/2">
                    <label class="block mb-2 font-bold">Канал:</label>
                    <Dropdown
                        v-model="selectedChannel"
                        :options="channels"
                        optionLabel="name"
                        placeholder="Выберите канал"
                        class="w-full"
                        :loading="loadingChannels"
                        :disabled="loadingChannels"
                    />
                    <small v-if="errorChannels" class="text-red-500">{{ errorChannels }}</small>
                </div>

                <div class="w-1/2">
                    <label class="block mb-2 font-bold">Период:</label>
                    <div class="flex gap-2">
                        <Calendar
                            v-model="startDate"
                            placeholder="Начало периода"
                            dateFormat="dd.mm.yy"
                            class="w-1/2"
                        />
                        <Calendar
                            v-model="endDate"
                            placeholder="Конец периода"
                            dateFormat="dd.mm.yy"
                            class="w-1/2"
                        />
                    </div>
                </div>
            </div>
            <Button
                label="Добавить выходы"
                @click="isModalVisible = true"
                class="mt-4"
            />
        </div>

        <!-- Нижняя часть с таблицей -->
        <div class="table-section">
            <DataTable
                :value="gridData"
                scrollable
                scrollDirection="horizontal"
                class="sticky-table"
            >
                <!-- Колонка канала -->
                <Column
                    field="channel"
                    :header="selectedChannel?.name || 'Канал'"
                    class="sticky-col"
                    headerClass="sticky-col-header"
                >
                    <template #body="{ data }">
                        <div>
                            <small>{{ data.media_product_name }} > {{ data.adv_block_name }} </small><br>
                            <strong>{{ formatTime(data.time) }}</strong>
                        </div>
                    </template>
                </Column>

                <!-- Динамические колонки с датами -->
                <Column
                    v-for="(date, index) in dateColumns"
                    :key="index"
                    :field="date.field"
                    :header="date.header"
                >
                    <template #body="{ data }">
                        <div
                            v-if="data.dates[date.field]"
                            :class="{ 'has-data': data.dates[date.field] }"
                        >
                            {{ data.dates[date.field].size}}&quot
                        </div>
                    </template>
                </Column>
            </DataTable>
        </div>


        <!-- Модальное окно -->
        <Dialog
            v-model:visible="isModalVisible"
            header="Добавить выходы рекламных блоков"
            :modal="true"
            :style="{ width: '40vw' }"
        >
        <!-- Форма внутри модального окна -->
        <div class="p-fluid">
            <!-- Селект рекламного блока и размер -->
            <div class="flex gap-4 mb-4">
                <div class="w-2/3"> <!-- 2/3 ширины -->
                    <label for="advBlock">Рекламный блок:</label>
                    <Dropdown
                        v-model="selectedAdvBlock"
                        :options="advBlocks"
                        optionLabel="name"
                        placeholder="Выберите рекламный блок"
                        class="w-full"
                    />
                </div>
                <div class="w-1/3"> <!-- 1/3 ширины -->
                    <label for="size">Размер:</label>
                    <InputNumber
                        v-model="size"
                        mode="decimal"
                        :min="0"
                        class="w-full"
                    />
                </div>
            </div>

            <!-- Дата начала, дата окончания и время -->
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

            <!-- Дни недели и кнопки массового выбора/снятия -->
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

        <!-- Кнопки Добавить и Отмена -->
        <template #footer>
            <Button
                label="Отмена"
                @click="isModalVisible = false"
                class="p-button-text"
            />
            <Button
                label="Добавить"
                @click="handleAdd"
                class="p-button-success"
            />
        </template>
        </Dialog>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import Dropdown from 'primevue/dropdown';
import Calendar from 'primevue/calendar';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import axios from 'axios';
import ChannelService from '@/services/ChannelService';
import AdvBlocksService from '@/services/AdvBlockService';

const channels = ref([]);
const selectedChannel = ref(null);
const startDate = ref(null);
const endDate = ref(null);
const loadingChannels = ref(false);
const errorChannels = ref(null);
const loading = ref(false);
const error = ref(null);
const gridData = ref([]);
const isModalVisible = ref(false);

// Загрузка списка рекламных блоков
const loadAdvBlocks = async () => {
    try {
        const params = selectedChannel.value ? { channel_id: selectedChannel.value.id } : {};
        const response = await AdvBlocksService.List(params);
        advBlocks.value = response.data.map(block => ({
            id: block.id,
            name: block.name,
        }));
    } catch (error) {
        console.error('Ошибка загрузки рекламных блоков:', error);
    }
};

const loadChannels = async () => {
    loadingChannels.value = true;
    errorChannels.value = null;

    try {
        const response = await ChannelService.List();
        channels.value = response.data.map(channel => ({
            id: channel.id,
            name: channel.name,
        }));
    } catch (err) {
        errorChannels.value = 'Не удалось загрузить каналы';
        console.error('Ошибка загрузки каналов:', err);
    } finally {
        loadingChannels.value = false;
    }
};

const formatTime = (time) => {
    return time.slice(0, 5); // Оставляем только часы и минуты (HH:MM)
};

const dateColumns = computed(() => {
    if (!startDate.value || !endDate.value) return [];

    const columns = [];
    const currentDate = new Date(startDate.value);
    const end = new Date(endDate.value);

    while (currentDate <= end) {
        const dateString = currentDate.toISOString().split('T')[0];
        const dateHeader = currentDate.toLocaleDateString('ru-RU', {
            day: '2-digit',
            month: '2-digit',
            weekday: 'short'
        }).replace(/\.$/, '');

        columns.push({
            field: dateString,
            header: dateHeader
        });

        currentDate.setDate(currentDate.getDate() + 1);
    }

    return columns;
});

const fetchData = async () => {
    if (!selectedChannel.value || !startDate.value || !endDate.value) return;

    loading.value = true;
    error.value = null;

    try {
        const params = {
            channel_id: selectedChannel.value.id,
            broadcast_at_from: startDate.value.toISOString().split('T')[0],
            broadcast_at_to: endDate.value.toISOString().split('T')[0],
        };

        const response = await axios.get('/api/advBlocksBroadcasting', { params });
        gridData.value = transformData(response.data.data);
    } catch (err) {
        error.value = 'Не удалось загрузить данные';
        console.error('Ошибка загрузки данных:', err);
    } finally {
        loading.value = false;
    }
};

const transformData = (data) => {
    const rows = {};

    data.forEach((item) => {
        const time = item.broadcast_at.split(' ')[1];
        const key = `${time}_${item.adv_block_id}`;

        if (!rows[key]) {
            rows[key] = {
                time: time,
                adv_block_id: item.adv_block_id,
                adv_block_name: item.adv_block.name,
                media_product_name: item.adv_block.media_product.name,
                channel_name: item.channel.name,
                dates: {},
            };
        }

        const date = item.broadcast_at.split(' ')[0];
        rows[key].dates[date] = item;
    });

    return Object.values(rows);
};

watch([selectedChannel, startDate, endDate], () => {
    fetchData();
}, { immediate: true });

// Загрузка данных при открытии модального окна
watch(isModalVisible, async (newValue) => {
    if (newValue) {
        await loadAdvBlocks();
    }
});

// Отслеживание изменения выбранного канала
watch(selectedChannel, async (newChannel) => {
    if (isModalVisible.value) {
        await loadAdvBlocks();
    }
});

onMounted(() => {
    const today = new Date();
    const twoWeeksLater = new Date();
    twoWeeksLater.setDate(today.getDate() + 14);

    startDate.value = today;
    endDate.value = twoWeeksLater;

    loadChannels();
    document.title = "Сетка выхода рекламных блоков";
});

// Данные формы
const selectedAdvBlock = ref(null);
const advBlocks = ref([
    { id: 1, name: 'Рекламный блок 1' },
    { id: 2, name: 'Рекламный блок 2' },
]);
const startDateForm = ref(null);
const endDateForm = ref(null);
const size = ref(0);
const time = ref('');
const selectedDays = ref([]);

// Дни недели
const daysOfWeek = ref([
    { label: 'Пн', value: 'mon' },
    { label: 'Вт', value: 'tue' },
    { label: 'Ср', value: 'wed' },
    { label: 'Чт', value: 'thu' },
    { label: 'Пт', value: 'fri' },
    { label: 'Сб', value: 'sat' },
    { label: 'Вс', value: 'sun' },
]);

// Методы для управления чекбоксами
const selectAllDays = () => {
    selectedDays.value = daysOfWeek.value.map(day => day.value);
};

const deselectAllDays = () => {
    selectedDays.value = [];
};

// Обработчик добавления
const handleAdd = () => {
    // Логика отправки данных на бэк
    console.log({
        advBlock: selectedAdvBlock.value,
        startDate: startDateForm.value,
        endDate: endDateForm.value,
        size: size.value,
        time: time.value,
        days: selectedDays.value,
    });

    // Закрываем модальное окно
    isModalVisible.value = false;
};
</script>


<style scoped>
.ad-grid-container {
    height: 100vh;
    display: flex;
    flex-direction: column;
}

.controls-section {
    background: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
}

.table-section {
    flex: 1;
    overflow: auto;
}

/* Увеличиваем ширину первой колонки */
.sticky-table ::v-deep(.sticky-col) {
    padding: 0.2rem 0.2rem !important;
    width: 200px!important; /* Ширина первой колонки */
    min-width: 200px!important; /* Минимальная ширина */
    max-width: 200px!important; /* Максимальная ширина */
}


/* Ширина остальных колонок */

.sticky-table ::v-deep(.p-datatable-tbody > tr > td) {
    padding: 0.2rem 0.2rem !important;
    width: 100px; /* Ширина остальных колонок */
    min-width: 100px; /* Минимальная ширина */
    max-width: 100px; /* Максимальная ширина */
}

.sticky-table ::v-deep(.p-datatable) {
    border-collapse: separate !important;
    border-spacing: 0;
}

.sticky-table ::v-deep(.p-datatable th),
.sticky-table ::v-deep(.p-datatable td) {
    border: 1px solid #dee2e6 !important;
}

.sticky-table ::v-deep(.p-datatable th),
.sticky-table ::v-deep(.p-datatable td) {
    border-width: 0.5px !important;
}

.sticky-table ::v-deep(.sticky-col) {
    position: sticky !important;
    left: 0 !important;
    z-index: 1 !important;
    background: white !important;
}

.sticky-table ::v-deep(.sticky-col-header) {
    position: sticky !important;
    left: 0 !important;
    z-index: 2 !important;
    background: white !important;
}

.sticky-table ::v-deep(.p-column-header) {
    border: 0.5px solid #dee2e6 !important;
    background: var(--surface-a) !important;
}

.sticky-table ::v-deep(.p-datatable-thead > tr > th) {
    border: 0.5px solid #dee2e6 !important;
}

.sticky-table ::v-deep(.p-datatable-tbody > tr > td.sticky-col) {
    border: 0.5px solid #dee2e6 !important;
}

.has-data {
    background-color: #e8f5e9; /* Светло-зеленый цвет */
    padding: 0.5rem; /* Добавим немного отступов для красоты */
    border-radius: 4px; /* Скруглим углы */
}

.p-fluid .field {
    margin-bottom: 1.5rem;
}

.flex.gap-2 {
    gap: 0.5rem;
}

.mt-2 {
    margin-top: 0.5rem;
}

.ml-2 {
    margin-left: 0.5rem;
}

</style>
