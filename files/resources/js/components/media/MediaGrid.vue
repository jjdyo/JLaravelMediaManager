<script setup lang="ts">
import { computed } from 'vue'

export interface MediaItem {
  id: number
  path: string
  url: string
  original_name: string
  thumbnails_urls?: Record<string, string>
}

const props = defineProps<{ items: MediaItem[]; selectedId?: number | null }>()
const emit = defineEmits<{ (e: 'select', item: MediaItem): void }>()

const normalized = computed(() => props.items || [])
</script>

<template>
  <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6">
    <button
      v-for="m in normalized"
      :key="m.id"
      type="button"
      class="group relative overflow-hidden rounded-md border bg-card text-card-foreground hover:bg-accent hover:text-accent-foreground"
      :class="{ 'ring-2 ring-blue-600': m.id === selectedId }"
      @click="emit('select', m)"
      title="Select"
    >
      <img
        :src="m.thumbnails_urls?.['64'] ?? m.thumbnails_urls?.['256'] ?? m.url"
        :alt="m.original_name"
        class="aspect-square w-full object-cover"
      />
      <div class="absolute inset-x-0 bottom-0 truncate bg-black/40 px-1 py-0.5 text-[10px] text-white">
        {{ m.original_name }}
      </div>
    </button>
  </div>
  <div v-if="!normalized.length" class="py-8 text-center text-sm text-muted-foreground">No media found.</div>

</template>
