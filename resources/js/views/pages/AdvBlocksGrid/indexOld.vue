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
                            @date-select="handleStartDateSelect"
                        />
                        <Calendar
                            v-model="endDate"
                            placeholder="Конец периода"
                            dateFormat="dd.mm.yy"
                            class="w-1/2"
                            @date-select="handleEndDateSelect"
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
            <!-- Прогресс-бар -->
            <div v-if="loadingProgress.isLoading" class="loading-container">
                <div class="loading-progress">
                    <div
                        class="progress-bar"
                        :style="{ width: `${(loadingProgress.currentPage / loadingProgress.totalPages) * 100}%` }"
                    ></div>
                </div>
                <div class="loading-info">
                    Страница {{ loadingProgress.currentPage }} из {{ loadingProgress.totalPages }} |
                    Загружено {{ loadingProgress.loaded }} из {{ loadingProgress.total }} записей
                </div>
            </div>

            <DataTable
                :value="gridData"
                scrollable
                scrollDirection="both"
                scrollHeight="flex"
                class="sticky-table"
                @rowContextmenu="onRowContextMenu"
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
                    v-for="(date, colIndex) in dateColumns"
                    :key="colIndex"
                    :field="date.field"

                >
                    <template #header="slotProps">
                        <div
                            @contextmenu.prevent="onHeaderContextMenu($event, slotProps)"
                            class="header-context-menu-target"
                        >
                            {{ date.header}}
                        </div>
                    </template>

                    <template #body="{ data }">
                        <div
                            v-if="data.dates[date.field]"
                            @contextmenu.prevent="onCellContextMenu($event, data.dates[date.field])"
                            class="context-menu-target"
                        >
                            {{ data.dates[date.field].size}}&quot
                        </div>
                    </template>

                </Column>
            </DataTable>
        </div>


        <!-- Контекстное меню -->
        <ContextMenu
            ref="contextMenu"
            :model="contextMenuItems"
            @hide="onContextMenuHide"
        />


        <!-- Контекстное меню для заголовков -->
        <ContextMenu
            ref="headerContextMenu"
            :model="headerContextMenuItems"
            @hide="onHeaderContextMenuHide"
        />

        <!-- Модальное окно для копирования дня -->
        <Dialog
            v-model:visible="isCopyModalVisible"
            header="Копировать выходы дня"
            :modal="true"
            :style="{ width: '40vw' }"
        >
            <div class="p-fluid">
                <div class="mb-4">
                    <label>Копировать выходы из:</label>
                    <InputText
                        v-model="copySourceDate"
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
                    @click="isCopyModalVisible = false"
                    class="p-button-text"
                />
                <Button
                    label="Копировать"
                    @click="executeDayCopy"
                    class="p-button-success"
                    :disabled="!canCopy"
                />
            </template>
        </Dialog>

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
                        @date-select="handleEndDateSelect"
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
import ContextMenu from 'primevue/contextmenu';


const channels = ref([]);
const selectedChannel = ref(null);
const startDate = ref(null);
const endDate = ref(null);
const loadingChannels = ref(false);
const errorChannels = ref(null);
const gridData = ref([]);
const isModalVisible = ref(false);


const loadingProgress = ref({
    currentPage: 0,
    totalPages: 1,
    isLoading: false,
    loaded: 0,
    total: 0
});

// Контекстное меню
const contextMenu = ref();
const contextMenuTarget = ref(null);
const contextMenuItems = ref([
    {
        label: 'Удалить выход',
        icon: 'pi pi-trash',
        command: () => deleteSelectedBroadcast()
    }
]);



// Контекстное меню для заголовков
const headerContextMenu = ref();
const headerContextMenuTarget = ref(null);
const headerContextMenuItems = ref([
    {
        label: 'Удалить все выходы',
        icon: 'pi pi-trash',
        command: () => deleteAllBroadcastsForDay()
    },
    {
        label: 'Копировать день',
        icon: 'pi pi-copy',
        command: () => copyDayBroadcasts()
    }
]);

const onRowContextMenu = (event) => {
    contextMenu.value.show(event.originalEvent);
};

const onCellContextMenu = (event, broadcastData) => {
    contextMenuTarget.value = broadcastData;
    contextMenu.value.show(event);
};

const onContextMenuHide = () => {
    contextMenuTarget.value = null;
};



const onHeaderContextMenu = (event, slotProps) => {
    headerContextMenuTarget.value = {
        date: slotProps.column.props.field, // Дата в формате YYYY-MM-DD
        header: slotProps.header // Отображаемый заголовок
    };
    headerContextMenu.value.show(event);
};

const onHeaderContextMenuHide = () => {
    headerContextMenuTarget.value = null;
};

const deleteAllBroadcastsForDay = async () => {
    if (!headerContextMenuTarget.value || !selectedChannel.value) return;

    try {
        const date = headerContextMenuTarget.value.date;

        // 1. Получаем все выходы для выбранного дня
        const response = await axios.get('/api/advBlocksBroadcasting', {
            params: {
                broadcast_at_from: date,
                broadcast_at_to: date,
                channel_id: selectedChannel.value.id,
                per_page: 1000 // Получаем все записи за день
            }
        });

        const broadcasts = response.data.data;

        if (broadcasts.length === 0) {
            return;
        }

        // 2. Подтверждение действия
        if (!confirm(`Вы уверены, что хотите удалить все выходы (${broadcasts.length} шт.) за ${date}?`)) {
            return;
        }

        // 3. Удаляем каждый выход отдельным запросом
        const deleteRequests = broadcasts.map(broadcast =>
            axios.delete(`/api/advBlocksBroadcasting/${broadcast.id}`)
        );

        await Promise.all(deleteRequests);
        await fetchData(); // Обновляем данные после удаления
        showSuccessToast(`Удалено ${broadcasts.length} выходов за ${headerContextMenuTarget.value.header}`);
    } catch (error) {
        console.error('Ошибка при удалении выходов:', error);
        showErrorToast('Не удалось удалить выходы');
    }
};

const deleteSelectedBroadcast = async () => {
    if (!contextMenuTarget.value) return;

    try {
        await axios.delete(`/api/advBlocksBroadcasting/${contextMenuTarget.value.id}`);
        await fetchData(); // Обновляем данные после удаления
        showSuccessToast('Выход успешно удален');
    } catch (error) {
        console.error('Ошибка при удалении выхода:', error);
        showErrorToast('Не удалось удалить выход');
    }
};

const showSuccessToast = (message) => {
    // Здесь можно использовать Toast из PrimeVue или другой способ показа уведомлений
    console.log('Success:', message);
};

const showErrorToast = (message) => {
    // Здесь можно использовать Toast из PrimeVue или другой способ показа уведомлений
    console.error('Error:', message);
};


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
const handleAdd = async () => {
    try {
        // Проверка заполнения обязательных полей
        if (!selectedAdvBlock.value || !startDate.value || !endDate.value || !time.value || !size.value || selectedDays.value.length === 0) {
            throw new Error('Заполните все обязательные поля');
        }

        // Проверка формата времени
        const timeParts = time.value.split(':');
        if (timeParts.length !== 3) {
            throw new Error('Неверный формат времени. Используйте hh:mm:ss');
        }

        const [hours, minutes, seconds] = timeParts.map(part => parseInt(part));
        if (isNaN(hours) || isNaN(minutes) || isNaN(seconds)) {
            throw new Error('Неверный формат времени');
        }

        // Получаем даты с учетом исправленного маппинга дней
        const dates = getDatesInRange(startDate.value, endDate.value, selectedDays.value);

        // Создаем и выполняем все запросы параллельно
        const requests = dates.map(date => {
            const broadcastAt = new Date(date);
            broadcastAt.setHours(hours, minutes, seconds);

            return axios.put('/api/advBlocksBroadcasting', {
                broadcast_at: formatDateTime(broadcastAt),
                adv_block_id: selectedAdvBlock.value.id,
                size: size.value
            });
        });

        await Promise.all(requests);
        // Закрываем модалку
        isModalVisible.value = false;
        // Обновляем данные таблицы используя СУЩЕСТВУЮЩИЙ метод
        await fetchData();

        console.log('Все выходы успешно добавлены');

    } catch (error) {
        console.error('Ошибка при добавлении выходов:', error);
        // Здесь можно добавить отображение ошибки пользователю
    }
};

// Функция для форматирования даты в Y-m-d H:i:s
const formatDateTime = (date) => {
    const pad = num => num.toString().padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ` +
        `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
};

// Функция для получения всех дат в диапазоне с учетом дней недели
const getDatesInRange = (startDate, endDate, selectedDays) => {
    const daysMap = {
        'mon': 1,
        'tue': 2,
        'wed': 3,
        'thu': 4,
        'fri': 5,
        'sat': 6,
        'sun': 0
    };

    const selectedDayNumbers = selectedDays.map(day => daysMap[day]);
    const dates = [];
    const currentDate = new Date(startDate);
    const end = new Date(endDate);

    while (currentDate <= end) {
        const dayOfWeek = currentDate.getDay();
        if (selectedDayNumbers.includes(dayOfWeek)) {
            dates.push(new Date(currentDate));
        }
        currentDate.setDate(currentDate.getDate() + 1);
    }

    return dates;
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

const fetchAllPages = async (baseParams) => {
    loadingProgress.value.isLoading = true;
    let allData = [];
    let currentPage = 1;
    let totalPages = 1;

    try {
        // Первый запрос для получения информации о пагинации
        const firstPage = await axios.get('/api/advBlocksBroadcasting', {
            params: { ...baseParams, page: currentPage }
        });

        allData = [...firstPage.data.data];
        totalPages = firstPage.data.last_page;
        loadingProgress.value.totalPages = totalPages;
        loadingProgress.value.currentPage = currentPage;
        loadingProgress.value.total = firstPage.data.total;

        // Загрузка остальных страниц
        while (currentPage < totalPages) {
            currentPage++;
            const response = await axios.get('/api/advBlocksBroadcasting', {
                params: { ...baseParams, page: currentPage }
            });

            allData = [...allData, ...response.data.data];
            loadingProgress.value.currentPage = currentPage;
            loadingProgress.value.loaded = allData.length;

            // Небольшая задержка для плавности прогресс-бара
            await new Promise(resolve => setTimeout(resolve, 50));
        }

        return allData;
    } finally {
        loadingProgress.value.totalPages = 0;
        loadingProgress.value.currentPage = 0;
        loadingProgress.value.total = 0;
        loadingProgress.value.loaded = 0;
        loadingProgress.value.isLoading = false;
    }
};

const fetchData = async () => {
    if (!selectedChannel.value || !startDate.value || !endDate.value) return;

    try {
        // Корректируем даты для запроса - без учета времени и временных зон
        const fromDate = new Date(startDate.value);
        fromDate.setUTCHours(0, 0, 0, 0);

        const toDate = new Date(endDate.value);
        toDate.setUTCHours(23, 59, 59, 999);

        const baseParams = {
            channel_id: selectedChannel.value.id,
            broadcast_at_from: formatDateForAPI(fromDate), // Формат YYYY-MM-DD
            broadcast_at_to: formatDateForAPI(toDate),     // Формат YYYY-MM-DD
            sort: 'broadcast_at',
            order: 'asc'
        };

        console.log('Отправляемые параметры:', baseParams); // Для отладки

        const allData = await fetchAllPages(baseParams);
        gridData.value = transformData(allData);

    } catch (error) {
        console.error('Ошибка загрузки:', error);
    }
};

const formatDateForAPI = (date) => {
    // Форматируем дату в YYYY-MM-DD без учета временной зоны
    const year = date.getUTCFullYear();
    const month = String(date.getUTCMonth() + 1).padStart(2, '0');
    const day = String(date.getUTCDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
};

const transformData = (data) => {
    const rows = {};

    data.forEach((item) => {
        const [date, time] = item.broadcast_at.split(' ');
        const key = `${time}_${item.adv_block_id}`;

        if (!rows[key]) {
            rows[key] = {
                time: time,
                adv_block_id: item.adv_block_id,
                adv_block_name: item.adv_block.name,
                channel_name: item.channel.name,
                media_product_name: item.adv_block.media_product.name,
                dates: {},
                // Добавляем поля для сортировки
                sortTime: time,
                sortName: item.adv_block.name.toLowerCase()
            };
        }

        rows[key].dates[date] = item;
    });

    // Сортируем сначала по времени, затем по названию
    return Object.values(rows).sort((a, b) => {
        if (a.sortTime === b.sortTime) {
            return a.sortName.localeCompare(b.sortName);
        }
        return a.sortTime.localeCompare(b.sortTime);
    });
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
watch(selectedChannel, async () => {
    if (isModalVisible.value) {
        await loadAdvBlocks();
    }
});

onMounted(() => {
    // Устанавливаем даты в UTC, чтобы избежать смещения
    const today = new Date();
    today.setUTCHours(0, 0, 0, 0);

    const twoWeeksLater = new Date();
    twoWeeksLater.setUTCDate(today.getUTCDate() + 14);
    twoWeeksLater.setUTCHours(23, 59, 59, 999);

    startDate.value = today;
    endDate.value = twoWeeksLater;

    loadChannels();
    document.title = "Сетка выхода рекламных блоков";
});

// Данные формы
const selectedAdvBlock = ref(null);
const advBlocks = ref();

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


// Данные для копирования дня
const isCopyModalVisible = ref(false);
const copySourceDate = ref('');
const copyStartDate = ref(null);
const copyEndDate = ref(null);
const copySelectedDays = ref([]);
const dayToCopyBroadcasts = ref([]);
const minCopyDate = ref(new Date());

const copyDayBroadcasts = async () => {
    if (!headerContextMenuTarget.value || !selectedChannel.value) return;

    try {
        const date = headerContextMenuTarget.value.date;
        copySourceDate.value = date;

        // Получаем все выходы для выбранного дня
        const response = await axios.get('/api/advBlocksBroadcasting', {
            params: {
                broadcast_at_from: date,
                broadcast_at_to: date,
                channel_id: selectedChannel.value.id,
                per_page: 1000
            }
        });

        dayToCopyBroadcasts.value = response.data.data;

        if (dayToCopyBroadcasts.value.length === 0) {
            showWarningToast('Нет выходов для копирования');
            return;
        }

        // Инициализируем данные для копирования
        copyStartDate.value = new Date(date);
        copyStartDate.value.setDate(copyStartDate.value.getDate() + 1); // Следующий день по умолчанию
        copyEndDate.value = new Date(copyStartDate.value);
        copySelectedDays.value = [...selectedDays.value]; // Копируем текущие выбранные дни

        // Минимальная дата - следующий день после копируемого
        minCopyDate.value = new Date(date);
        minCopyDate.value.setDate(minCopyDate.value.getDate() + 1);

        isCopyModalVisible.value = true;
    } catch (error) {
        console.error('Ошибка при получении выходов для копирования:', error);
        showErrorToast('Не удалось получить данные для копирования');
    }
};

const canCopy = computed(() => {
    return copyStartDate.value && copyEndDate.value && copySelectedDays.value.length > 0;
});

const executeDayCopy = async () => {
    if (!canCopy.value || dayToCopyBroadcasts.value.length === 0) return;

    try {
        // Группируем выходы по блокам и времени
        const broadcastsByBlockAndTime = {};

        dayToCopyBroadcasts.value.forEach(broadcast => {
            const time = broadcast.broadcast_at.split(' ')[1]; // Получаем время HH:MM:SS
            const key = `${broadcast.adv_block_id}_${time}`;

            if (!broadcastsByBlockAndTime[key]) {
                broadcastsByBlockAndTime[key] = {
                    adv_block_id: broadcast.adv_block_id,
                    size: broadcast.size,
                    time: time,
                    days: []
                };
            }
        });

        // Получаем даты в выбранном диапазоне
        const datesToCopy = getDatesInRange(copyStartDate.value, copyEndDate.value, copySelectedDays.value);

        if (datesToCopy.length === 0) {
            showWarningToast('Нет дат для копирования по выбранным параметрам');
            return;
        }

        // Подтверждение действия
        if (!confirm(`Скопировать ${dayToCopyBroadcasts.value.length} выходов в ${datesToCopy.length} дней?`)) {
            return;
        }

        // Создаем все выходы
        const createRequests = [];

        datesToCopy.forEach(date => {
            Object.values(broadcastsByBlockAndTime).forEach(broadcast => {
                const [hours, minutes, seconds] = broadcast.time.split(':');
                const broadcastAt = new Date(date);
                broadcastAt.setHours(hours, minutes, seconds);

                createRequests.push(
                    axios.put('/api/advBlocksBroadcasting', {
                        broadcast_at: formatDateTime(broadcastAt),
                        adv_block_id: broadcast.adv_block_id,
                        size: broadcast.size
                    })
                );
            });
        });

        // Выполняем запросы пачками по 20, чтобы не перегружать сервер
        const batchSize = 20;
        for (let i = 0; i < createRequests.length; i += batchSize) {
            const batch = createRequests.slice(i, i + batchSize);
            await Promise.all(batch);

            // Обновляем прогресс
            const progress = Math.min(i + batchSize, createRequests.length);
            console.log(`Создано ${progress} из ${createRequests.length} выходов`);
        }

        await fetchData(); // Обновляем данные после копирования
        showSuccessToast(`Успешно скопировано ${dayToCopyBroadcasts.value.length} выходов в ${datesToCopy.length} дней`);
        isCopyModalVisible.value = false;
    } catch (error) {
        console.error('Ошибка при копировании выходов:', error);
        showErrorToast('Не удалось скопировать выходы');
    } finally {
        dayToCopyBroadcasts.value = [];
    }
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
    position: sticky !important;
    left: 0 !important;
    z-index: 2 !important;
    background: white !important;
    box-shadow: 2px 0 5px -2px rgba(0, 0, 0, 0.1); /* Тень справа */
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


/* Фиксированная шапка таблицы */
.sticky-table ::v-deep(.p-datatable-thead) {
    position: sticky;
    top: 0;
    z-index: 3; /* Выше чем у sticky колонок */
    background: var(--surface-a) !important;
}

/* Тень для фиксированной шапки */
.sticky-table ::v-deep(.p-datatable-thead) {
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}


.sticky-table ::v-deep(.p-datatable th),
.sticky-table ::v-deep(.p-datatable td) {
    border: 1px solid #dee2e6 !important;
}

.sticky-table ::v-deep(.p-datatable th),
.sticky-table ::v-deep(.p-datatable td) {
    border-width: 0.5px !important;
}


.sticky-table ::v-deep(.sticky-col-header) {
    position: sticky !important;
    left: 0 !important;
    z-index: 4 !important;
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
.loading-progress {
    position: relative;
    height: 24px;
    background: #f0f0f0;
    border-radius: 4px;
    margin-bottom: 12px;
    overflow: hidden;
}

.progress-bar {
    height: 100%;
    background: #4CAF50;
    transition: width 0.3s ease;
}

.context-menu-target {
    cursor: context-menu;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.context-menu-target:hover {
    background-color: #f0f0f0;
}

</style>
