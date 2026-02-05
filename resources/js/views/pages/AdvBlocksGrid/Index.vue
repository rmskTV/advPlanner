<template>
    <div class="ad-grid-container">
        <ControlsPanel
            :channels="channels"
            :selected-channel="selectedChannel"
            :media-products="mediaProducts"
            :selected-media-product="selectedMediaProduct"
            :start-date="startDate"
            :end-date="endDate"
            :loading-channels="loadingChannels"
            :error-channels="errorChannels"
            :media-products-loading="mediaProductsLoading"
            @update:selectedMediaProduct="selectedMediaProduct = $event"
            @update:selectedChannel="selectedChannel = $event"
            @update:startDate="startDate = $event"
            @update:endDate="endDate = $event"
            @openAddModal="isModalVisible = true"
        />

        <BroadcastTable
            :grid-data="gridData"
            :date-columns="dateColumns"
            :loading-progress="loadingProgress"
            :selected-channel="selectedChannel"
            @rowContextMenu="onRowContextMenu"
            @headerContextMenu="onHeaderContextMenu"
            @cellContextMenu="onCellContextMenu"
        />

        <AddBroadcastModal
            :modelValue="isModalVisible"
            @update:modelValue="isModalVisible = $event"
            :adv-blocks="advBlocks"
            :days-of-week="daysOfWeek"
            :selected-channel="selectedChannel"
            @submit="handleAdd"
        />

        <CopyDayModal
            :modelValue="isCopyModalVisible"
            @update:modelValue="isCopyModalVisible = $event"
            :sourceDate="copySourceDate"
            @update:sourceDate="copySourceDate = $event"
            :days-of-week="daysOfWeek"
            :selected-channel="selectedChannel"
            :day-to-copy-broadcasts="dayToCopyBroadcasts"
            @submit="executeDayCopy"
        />

        <ContextMenu
            ref="contextMenu"
            :model="contextMenuItems"
            @hide="onContextMenuHide"
        />

        <ContextMenu
            ref="headerContextMenu"
            :model="headerContextMenuItems"
            @hide="onHeaderContextMenuHide"
        />
        <ConfirmDialog></ConfirmDialog>
    </div>
</template>


<script setup>
import {computed, onMounted, ref, watch} from 'vue';
import axios from 'axios';
import ChannelService from '@/services/ChannelService';
import MediaProductsService from '@/services/MediaProductsService';
import AdvBlocksService from '@/services/AdvBlockService';
import ControlsPanel from './ControlsPanel.vue';
import BroadcastTable from './BroadcastTable.vue';
import AddBroadcastModal from './AddBroadcastModal.vue';
import CopyDayModal from './CopyDayModal.vue';

//Конфирмы
import ConfirmDialog from 'primevue/confirmdialog';
import { useConfirm } from "primevue/useconfirm";
const confirm = useConfirm();

// Реактивные данные
const channels = ref([]);
const mediaProducts = ref([]);
const selectedChannel = ref(null);
const selectedMediaProduct = ref(null);
const loadingMediaProducts = ref(false);
const startDate = ref(null);
const endDate = ref(null);
const loadingChannels = ref(false);
const errorChannels = ref(null);
const gridData = ref([]);
const isModalVisible = ref(false);
const advBlocks = ref([]);
const loadingProgress = ref({
    currentPage: 0,
    totalPages: 1,
    isLoading: false,
    loaded: 0,
    total: 0
});

const mediaProductsLoading = ref({
    isLoading: false,
    currentPage: 0,
    totalPages: 1,
    loaded: 0,
    total: 0
});
// Контекстные меню
const contextMenu = ref();
const contextMenuTarget = ref(null);
const headerContextMenu = ref();
const headerContextMenuTarget = ref(null);
const contextMenuItems = ref([{ label: 'Удалить выход', icon: 'pi pi-trash', command: () => deleteSelectedBroadcast() }]);
const headerContextMenuItems = ref([
    { label: 'Удалить все выходы', icon: 'pi pi-trash', command: () => deleteAllBroadcastsForDay() },
    { label: 'Копировать день', icon: 'pi pi-copy', command: () => copyDayBroadcasts() }
]);

// Данные для копирования дня
const isCopyModalVisible = ref(false);
const copySourceDate = ref('');
const dayToCopyBroadcasts = ref([]);

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


// Методы
const loadChannels = async () => {
    loadingChannels.value = true;
    errorChannels.value = null;
    try {
        const response = await ChannelService.List();
        channels.value = response.data.map(channel => ({ id: channel.id, name: channel.name }));
    } catch (err) {
        errorChannels.value = 'Не удалось загрузить каналы';
        console.error('Ошибка загрузки каналов:', err);
    } finally {
        loadingChannels.value = false;
    }
};

const loadMediaProducts = async () => {
    if (!selectedChannel.value) return;

    mediaProductsLoading.value = {
        isLoading: true,
        currentPage: 0,
        totalPages: 1,
        loaded: 0,
        total: 0
    };

    try {
        // Загружаем первую страницу
        const firstPage = await MediaProductsService.List({
            channel_id: selectedChannel.value.id,
            per_page: 100
        });

        // Инициализируем массив с пустым вариантом
        let allProducts = [{ id: null, name: 'Все медиапродукты' }];
        allProducts = [...allProducts, ...firstPage.data];

        mediaProductsLoading.value = {
            ...mediaProductsLoading.value,
            totalPages: firstPage.last_page,
            currentPage: 1,
            total: firstPage.total
        };

        // Если есть еще страницы - загружаем их
        if (firstPage.last_page > 1) {
            const requests = [];
            for (let page = 2; page <= firstPage.last_page; page++) {
                requests.push(
                    MediaProductsService.List({
                        channel_id: selectedChannel.value.id,
                        page: page,
                        per_page: 100
                    })
                );
            }

            const responses = await Promise.all(requests);
            responses.forEach(response => {
                allProducts = [...allProducts, ...response.data];
                mediaProductsLoading.value.currentPage++;
                mediaProductsLoading.value.loaded = allProducts.length - 1; // -1 учитываем пустой вариант
            });
        }

        mediaProducts.value = allProducts;
    } catch (err) {
        console.error('Ошибка загрузки медиапродуктов:', err);
    } finally {
        mediaProductsLoading.value.isLoading = false;
    }
};

const fetchData = async () => {
    if (!selectedChannel.value || !startDate.value || !endDate.value) return;

    try {
        const fromDate = new Date(startDate.value);
        fromDate.setUTCHours(0, 0, 0, 0);

        const toDate = new Date(endDate.value);
        toDate.setUTCHours(23, 59, 59, 999);

        const baseParams = {
            channel_id: selectedChannel.value.id,
            broadcast_at_from: formatDateForAPI(fromDate),
            broadcast_at_to: formatDateForAPI(toDate),
            sort: 'broadcast_at',
            order: 'asc'
        };

        // Добавляем фильтр по медиапродукту только если выбран конкретный продукт
        if (selectedMediaProduct.value && selectedMediaProduct.value.id) {
            baseParams.media_product_id = selectedMediaProduct.value.id;
        }

        const allData = await fetchAllPages(baseParams);
        gridData.value = transformData(allData);
    } catch (error) {
        console.error('Ошибка загрузки:', error);
    }
};

const fetchAllPages = async (baseParams) => {
    loadingProgress.value = {
        isLoading: true,
        currentPage: 0,
        totalPages: 1,
        loaded: 0,
        total: 0
    };
    let allData = [];
    let currentPage = 1;
    let totalPages = 1;

    try {
        const firstPage = await axios.get('/advBlocksBroadcasting', {
            params: { ...baseParams, page: currentPage, per_page: 200 }
        });

        allData = [...firstPage.data.data];
        totalPages = firstPage.data.last_page;
        loadingProgress.value = {
            ...loadingProgress.value,
            totalPages,
            currentPage,
            total: firstPage.data.total
        };

        while (currentPage < totalPages) {
            currentPage++;
            const response = await axios.get('/advBlocksBroadcasting', {
                params: { ...baseParams, page: currentPage, per_page: 200 }
            });

            allData = [...allData, ...response.data.data];
            loadingProgress.value.currentPage = currentPage;
            loadingProgress.value.loaded = allData.length;
            await new Promise(resolve => setTimeout(resolve, 50));
        }

        return allData;
    } finally {
        loadingProgress.value.isLoading = false;
    }
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
                sortTime: time,
                sortName: item.adv_block.name.toLowerCase()
            };
        }

        rows[key].dates[date] = item;
    });

    return Object.values(rows).sort((a, b) => {
        return a.sortTime === b.sortTime
            ? a.sortName.localeCompare(b.sortName)
            : a.sortTime.localeCompare(b.sortTime);
    });
};

const formatDateForAPI = (date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
};

const formatDateTime = (date) => {
    const pad = num => num.toString().padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ` +
        `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
};

const getDatesInRange = (start, end, selectedDays) => {
    const daysMap = {
        'mon': 1, 'tue': 2, 'wed': 3, 'thu': 4,
        'fri': 5, 'sat': 6, 'sun': 0
    };

    const selectedDayNumbers = selectedDays.map(day => daysMap[day]);
    const dates = [];
    const current = new Date(start);
    const endDate = new Date(end);

    while (current <= endDate) {
        if (selectedDayNumbers.includes(current.getDay())) {
            dates.push(new Date(current));
        }
        current.setDate(current.getDate() + 1);
    }

    return dates;
};

const handleAdd = async ({ advBlock, size, time, startDate, endDate, days }) => {
    try {
        const dates = getDatesInRange(startDate, endDate, days);
        const [hours, minutes, seconds] = time.split(':');

        const requests = dates.map(date => {
            const broadcastAt = new Date(date);
            broadcastAt.setHours(hours, minutes, seconds);

            return axios.put('/advBlocksBroadcasting', {
                broadcast_at: formatDateTime(broadcastAt),
                adv_block_id: advBlock.id,
                size: size
            });
        });

        await Promise.all(requests);
        isModalVisible.value = false;
        await fetchData();
    } catch (error) {
        console.error('Ошибка при добавлении выходов:', error);
    }
};

const deleteSelectedBroadcast = async () => {
    if (!contextMenuTarget.value) return;

    confirm.require({
        message: 'Вы действительно хотите удалить этот выход?',
        header: 'Подтверждение удаления',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Удалить',
        rejectLabel: 'Отмена',
        rejectClass: 'p-button-text', // Используем встроенный класс PrimeVue для текстовой кнопки
        acceptClass: 'p-button-danger', // Красный цвет для кнопки удаления
        accept: async () => {
            try {
                await axios.delete(`/advBlocksBroadcasting/${contextMenuTarget.value.id}`);
                await fetchData();
            } catch (error) {
                console.error('Ошибка при удалении выхода:', error);
            }
        }
    });
};

const deleteAllBroadcastsForDay = async () => {
    if (!headerContextMenuTarget.value || !selectedChannel.value) return;

    confirm.require({
        message: 'Вы действительно хотите удалить все выходы за выбранный день?',
        header: 'Подтверждение удаления',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Удалить',
        rejectLabel: 'Отмена',
        rejectClass: 'p-button-text', // Используем встроенный класс PrimeVue для текстовой кнопки
        acceptClass: 'p-button-danger', // Красный цвет для кнопки удаления
        accept: async () => {
            try {
                const date = headerContextMenuTarget.value.date;
                const response = await axios.get('/advBlocksBroadcasting', {
                    params: {
                        broadcast_at_from: date,
                        broadcast_at_to: date,
                        channel_id: selectedChannel.value.id,
                        per_page: 1000
                    }
                });

                const broadcasts = response.data.data;
                if (broadcasts.length === 0) return;

                const deleteRequests = broadcasts.map(broadcast =>
                    axios.delete(`/advBlocksBroadcasting/${broadcast.id}`)
                );

                await Promise.all(deleteRequests);
                await fetchData();
            } catch (error) {
                console.error('Ошибка при удалении выходов:', error);
            }
        }
    });
};

const copyDayBroadcasts = async () => {
    if (!headerContextMenuTarget.value || !selectedChannel.value) return;

    try {
        const date = headerContextMenuTarget.value.date;
        copySourceDate.value = date;

        const response = await axios.get('/advBlocksBroadcasting', {
            params: {
                broadcast_at_from: date,
                broadcast_at_to: date,
                channel_id: selectedChannel.value.id,
                per_page: 1000
            }
        });

        dayToCopyBroadcasts.value = response.data.data;
        isCopyModalVisible.value = true;
    } catch (error) {
        console.error('Ошибка при копировании дня:', error);
    }
};

const executeDayCopy = async ({ startDate, endDate, days }) => {
    try {
        const datesToCopy = getDatesInRange(startDate, endDate, days);
        const broadcastsByBlockAndTime = {};

        dayToCopyBroadcasts.value.forEach(broadcast => {
            const time = broadcast.broadcast_at.split(' ')[1];
            const key = `${broadcast.adv_block_id}_${time}`;
            broadcastsByBlockAndTime[key] = {
                adv_block_id: broadcast.adv_block_id,
                size: broadcast.size,
                time: time
            };
        });

        const createRequests = datesToCopy.flatMap(date =>
            Object.values(broadcastsByBlockAndTime).map(broadcast => {
                const [hours, minutes, seconds] = broadcast.time.split(':');
                const broadcastAt = new Date(date);
                broadcastAt.setHours(hours, minutes, seconds);

                return axios.put('/advBlocksBroadcasting', {
                    broadcast_at: formatDateTime(broadcastAt),
                    adv_block_id: broadcast.adv_block_id,
                    size: broadcast.size
                });
            })
        );

        await Promise.all(createRequests);
        await fetchData();
        isCopyModalVisible.value = false;
    } catch (error) {
        console.error('Ошибка при выполнении копирования:', error);
    }
};

// Добавьте вычисляемое свойство dateColumns
const dateColumns = computed(() => {
    if (!startDate.value || !endDate.value) return [];

    const columns = [];
    const current = new Date(startDate.value);
    const end = new Date(endDate.value);

    while (current <= end) {
        const dateStr = formatDateForAPI(current); // Используем уже имеющуюся функцию
        const displayDate = current.toLocaleDateString('ru-RU', {
            day: '2-digit',
            month: '2-digit',
            weekday: 'short'
        }).replace(/\.$/, '');

        columns.push({
            field: dateStr, // Должно совпадать с ключами в data.dates
            header: displayDate
        });

        current.setDate(current.getDate() + 1);
    }
    return columns;
});

const onRowContextMenu = (event) => {
    contextMenu.value.show(event.originalEvent);
    contextMenuTarget.value = event.data;
};

const onHeaderContextMenu = (event) => {
    headerContextMenu.value.show(event.originalEvent);
    headerContextMenuTarget.value = {
        date: event.column.field,
        header: event.column.header
    };
};

const onCellContextMenu = (event) => {
    contextMenu.value.show(event.originalEvent);
    contextMenuTarget.value = event.data;
};

const onContextMenuHide = () => {
    contextMenuTarget.value = null;
};

const onHeaderContextMenuHide = () => {
    headerContextMenuTarget.value = null;
};


// Хуки
onMounted(() => {
    const today = new Date();
    today.setUTCHours(0, 0, 0, 0);
    const twoWeeksLater = new Date(today);
    twoWeeksLater.setUTCDate(today.getUTCDate() + 14);
    twoWeeksLater.setUTCHours(23, 59, 59, 999);

    startDate.value = today;
    endDate.value = twoWeeksLater;
    loadChannels();
    loadMediaProducts();

    // Устанавливаем начальное значение "Все медиапродукты"
    selectedMediaProduct.value = { id: null, name: 'Все медиапродукты' };

    document.title = "Сетка выхода рекламных блоков";
});

// Вотчеры
watch([selectedChannel, selectedMediaProduct, startDate, endDate], fetchData, { immediate: true });
watch(isModalVisible, async (newValue) => {
    if (newValue && selectedChannel.value) {
        try {
            // Сначала загружаем первую страницу
            const firstPage = await AdvBlocksService.List({
                channel_id: selectedChannel.value.id,
                per_page: 100 // увеличиваем количество элементов на странице
            });

            // Если есть другие страницы, загружаем их
            if (firstPage.last_page > 1) {
                const requests = [];
                for (let page = 2; page <= firstPage.last_page; page++) {
                    requests.push(
                        AdvBlocksService.List({
                            channel_id: selectedChannel.value.id,
                            per_page: 100,
                            page: page
                        })
                    );
                }

                const otherPages = await Promise.all(requests);
                const allBlocks = [
                    ...firstPage.data,
                    ...otherPages.flatMap(page => page.data)
                ];

                advBlocks.value = allBlocks.map(block => ({
                    id: block.id,
                    name: block.name,
                    size: block.size
                }));
            } else {
                advBlocks.value = firstPage.data.map(block => ({
                    id: block.id,
                    name: block.name,
                    size: block.size
                }));
            }
        } catch (error) {
            console.error('Ошибка загрузки рекламных блоков:', error);
        }
    }
});
watch(selectedChannel, (newVal) => {
    if (newVal) {
        mediaProducts.value = [{ id: null, name: 'Все медиапродукты' }]; // Сброс списка
        loadMediaProducts();
    }
}, { immediate: true });
</script>

<style scoped>
.ad-grid-container {
    height: 100vh;
    display: flex;
    flex-direction: column;
}

.p-contextmenu {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    border-radius: 4px;
}

.p-contextmenu .p-menuitem-link {
    padding: 0.5rem 1rem;
}

.p-contextmenu .p-menuitem-link:hover {
    background-color: #f8f9fa;
}

</style>
