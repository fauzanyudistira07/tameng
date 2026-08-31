<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    currentPage: number
    pageSize: number
    totalItems: number
    pageSizeOptions?: number[]
  }>(),
  {
    pageSizeOptions: () => [5, 10, 20, 50],
  },
)

const emit = defineEmits<{
  (e: 'update:currentPage', page: number): void
  (e: 'update:pageSize', size: number): void
}>()

const totalPages = computed(() => Math.max(1, Math.ceil(props.totalItems / props.pageSize)))
const startItem = computed(() => (props.totalItems === 0 ? 0 : (props.currentPage - 1) * props.pageSize + 1))
const endItem = computed(() => Math.min(props.currentPage * props.pageSize, props.totalItems))

function setPage(page: number) {
  if (page >= 1 && page <= totalPages.value && page !== props.currentPage) {
    emit('update:currentPage', page)
  }
}

function onPageSizeChange(e: Event) {
  const newSize = Number((e.target as HTMLSelectElement).value)
  emit('update:pageSize', newSize)
  emit('update:currentPage', 1)
}

const visiblePages = computed(() => {
  const current = props.currentPage
  const total = totalPages.value
  const delta = 1
  const range: number[] = []
  const rangeWithDots: (number | string)[] = []
  let l: number | undefined

  for (let i = 1; i <= total; i++) {
    if (i === 1 || i === total || (i >= current - delta && i <= current + delta)) {
      range.push(i)
    }
  }

  for (const i of range) {
    if (l) {
      if (i - l === 2) {
        rangeWithDots.push(l + 1)
      } else if (i - l !== 1) {
        rangeWithDots.push('...')
      }
    }
    rangeWithDots.push(i)
    l = i
  }

  return rangeWithDots
})
</script>

<template>
  <div v-if="totalItems > 0" class="tameng-pagination">
    <!-- Info Section -->
    <div class="pagination-info">
      <span>
        Menampilkan <strong>{{ startItem }}</strong> - <strong>{{ endItem }}</strong> dari <strong>{{ totalItems }}</strong> data
      </span>
      
      <!-- Page Size Selector -->
      <div class="page-size-selector">
        <label for="pageSizeSelect">Per halaman:</label>
        <select id="pageSizeSelect" :value="pageSize" @change="onPageSizeChange">
          <option v-for="opt in pageSizeOptions" :key="opt" :value="opt">
            {{ opt }}
          </option>
        </select>
      </div>
    </div>

    <!-- Navigation Buttons -->
    <div class="pagination-controls">
      <!-- First Page -->
      <button
        class="page-btn"
        :disabled="currentPage === 1"
        title="Halaman Pertama"
        type="button"
        @click="setPage(1)"
      >
        «
      </button>

      <!-- Previous Page -->
      <button
        class="page-btn"
        :disabled="currentPage === 1"
        title="Halaman Sebelumnya"
        type="button"
        @click="setPage(currentPage - 1)"
      >
        ‹
      </button>

      <!-- Page Numbers -->
      <template v-for="(p, index) in visiblePages" :key="index">
        <span v-if="p === '...'" class="page-ellipsis">...</span>
        <button
          v-else
          class="page-btn page-num"
          :class="{ active: p === currentPage }"
          type="button"
          @click="setPage(Number(p))"
        >
          {{ p }}
        </button>
      </template>

      <!-- Next Page -->
      <button
        class="page-btn"
        :disabled="currentPage === totalPages"
        title="Halaman Berikutnya"
        type="button"
        @click="setPage(currentPage + 1)"
      >
        ›
      </button>

      <!-- Last Page -->
      <button
        class="page-btn"
        :disabled="currentPage === totalPages"
        title="Halaman Terakhir"
        type="button"
        @click="setPage(totalPages)"
      >
        »
      </button>
    </div>
  </div>
</template>

<style scoped>
.tameng-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-top: 16px;
  padding-top: 14px;
  border-top: 1px solid var(--border-subtle);
  flex-wrap: wrap;
}

.pagination-info {
  display: flex;
  align-items: center;
  gap: 16px;
  font-size: 12.5px;
  color: var(--text-muted);
}

.pagination-info strong {
  color: var(--tameng-navy);
}

.page-size-selector {
  display: flex;
  align-items: center;
  gap: 8px;
}

.page-size-selector label {
  font-size: 12px;
  color: var(--text-dim);
  font-weight: 600;
}

.page-size-selector select {
  min-height: 30px;
  padding: 2px 8px;
  font-size: 12px;
  width: auto;
  border-radius: 6px;
  border: 1px solid var(--border-card);
  background: #ffffff;
  color: var(--tameng-navy);
  font-weight: 700;
}

.pagination-controls {
  display: flex;
  align-items: center;
  gap: 4px;
}

.page-btn {
  min-width: 32px;
  min-height: 32px;
  height: 32px;
  padding: 0 8px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: #ffffff;
  border: 1px solid var(--border-card);
  color: var(--tameng-navy);
  border-radius: 6px;
  font-size: 12.5px;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 1px 2px rgba(15, 35, 70, 0.04);
  transition: all 0.15s ease-out;
}

.page-btn:hover:not(:disabled) {
  background: var(--bg-surface-elevated);
  border-color: var(--tameng-sapphire);
  color: var(--tameng-sapphire);
  transform: translateY(-1px);
}

.page-btn.active {
  background: var(--tameng-sapphire);
  border-color: var(--tameng-sapphire);
  color: #ffffff;
  box-shadow: 0 2px 6px rgba(29, 78, 216, 0.25);
}

.page-btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
  transform: none;
  box-shadow: none;
}

.page-ellipsis {
  padding: 0 4px;
  color: var(--text-dim);
  font-weight: 700;
  font-size: 13px;
}
</style>
