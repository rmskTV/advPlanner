<template>
    <div class="table-section">


        <LoadingProgress
            v-if="loadingProgress.isLoading"
            :progress="loadingProgress"
        />

        <DataTable
            :value="gridData"
            scrollable
            scrollDirection="both"
            scrollHeight="flex"
            class="sticky-table"
            @rowContextmenu="onRowContextMenu"
        >
            <Column
                field="channel"
                :header="selectedChannel?.name || 'Канал'"
                class="sticky-col first-column"
                headerClass="sticky-col-header first-column-header"
            >
                <template #body="{ data }">
                    <div>
                        <small>{{ data.media_product_name }} > {{ data.adv_block_name }}</small>
                        <br>
                        <strong>{{ data.time.slice(0, 5) }}</strong>
                    </div>
                </template>
            </Column>

            <Column
                v-for="dateCol in dateColumns"
                :key="dateCol.field"
                :field="dateCol.field"
            >
                <template #header="slotProps">
                    <div
                        @contextmenu.prevent="onHeaderContextMenu($event, dateCol)"
                        class="header-context-menu-target"
                    >
                        {{ dateCol.header }}
                    </div>
                </template>

                <template #body="{ data }">
                    <div
                        v-if="data.dates[dateCol.field]"
                        @contextmenu.prevent="onCellContextMenu($event, data.dates[dateCol.field])"
                        class="context-menu-target cell-content"
                    >
                        {{ data.dates[dateCol.field].size }}&quot;
                    </div>
                    <div v-else class="empty-cell">-</div>
                </template>
            </Column>
        </DataTable>
    </div>
</template>

<script setup>
import LoadingProgress from "./LoadingProgress.vue";
import { defineProps, defineEmits } from 'vue';
import Column from "primevue/column";

const props = defineProps({
    gridData: Array,
    dateColumns: Array,
    loadingProgress: Object,
    selectedChannel: Object
});

const emit = defineEmits([
    'rowContextMenu',
    'headerContextMenu',
    'cellContextMenu'
]);

const onRowContextMenu = (event, rowData) => {
    emit('rowContextMenu', { originalEvent: event, data: rowData });
};

const onHeaderContextMenu = (event, column) => {
    emit('headerContextMenu', { originalEvent: event, column });
};

const onCellContextMenu = (event, cellData) => {
    emit('cellContextMenu', { originalEvent: event, data: cellData });
};
</script>

<style scoped>
.context-menu-target {
    cursor: context-menu;
    height: 100%;
    padding: 0.5rem;
}

.context-menu-target:hover {
    background-color: rgba(0, 0, 0, 0.05);
}

.header-context-menu-target {
    cursor: context-menu;
    padding: 0.5rem;
}

.header-context-menu-target:hover {
    background-color: rgba(0, 0, 0, 0.05);
}

.empty-cell {
    color: #ccc;
    text-align: center;
    padding: 0.5rem;
}

.cell-content {
    display: flex;
    align-items: center;
    justify-content: center;
}

.first-column {
    min-width: 200px !important;
    width: 200px !important;
    max-width: 200px !important;
}

.first-column-header {
    min-width: 200px !important;
    width: 200px !important;
    max-width: 200px !important;
}

/* Обновляем стили для sticky колонки */
.sticky-table ::v-deep(.sticky-col) {
    min-width: 200px !important;
    width: 200px !important;
    max-width: 200px !important;
    position: sticky !important;
    left: 0 !important;
    z-index: 2 !important;
    background: white !important;
    box-shadow: 2px 0 5px -2px rgba(0, 0, 0, 0.1);
}

.sticky-table ::v-deep(.sticky-col-header) {
    min-width: 200px !important;
    width: 200px !important;
    max-width: 200px !important;
    position: sticky !important;
    left: 0 !important;
    z-index: 3 !important;
}
</style>
