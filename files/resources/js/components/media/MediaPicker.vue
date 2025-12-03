<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import { useMediaApi, type MediaItem, type Paginated } from '@/composables/useMediaApi'
import MediaGrid from './MediaGrid.vue'

const props = defineProps<{ contextDir: string }>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'select', item: MediaItem): void }>()

const { list, upload, directories, createFolder } = useMediaApi()

const q = ref('')
const page = ref(1)
const perPage = ref(10)
const loading = ref(false)
const error = ref<string | null>(null)
const items = ref<MediaItem[]>([])
const total = ref(0)
const currentPage = ref(1)
const lastPage = ref(1)
const selectedId = ref<number | null>(null)
const uploading = ref(false)
const dupPayload = ref<any | null>(null)
const hiddenFileInput = ref<HTMLInputElement | null>(null)

// Directories/config
const dirsFlat = ref<string[]>([])
const selectedDir = ref<string>('')
const maxSizeLabel = ref<string>('')
const allowNest = ref<number>(3)
const tab = ref<'folder' | 'all'>('folder')

async function loadDirectories() {
  try {
    const resp = await directories()
    dirsFlat.value = Array.isArray(resp?.flat) ? resp.flat : []
    maxSizeLabel.value = String(resp?.config?.max_file_size ?? '')
    allowNest.value = Number(resp?.config?.allowed_folder_nest ?? 3)
    // Default selection: contextDir if included, else first root
    if (!selectedDir.value) {
      selectedDir.value = dirsFlat.value.includes(props.contextDir) ? props.contextDir : (dirsFlat.value[0] || props.contextDir)
    }
  } catch (e: any) {
    // Silent fail for directories; picker can still work with contextDir
    selectedDir.value = props.contextDir
  }
}

async function load() {
  loading.value = true
  error.value = null
  try {
    const targetDir = selectedDir.value || props.contextDir
    const params = {
      q: q.value || undefined,
      page: page.value,
      per_page: perPage.value,
      ...(tab.value === 'folder' ? { dir: targetDir } : {}),
    } as any
    const res = await list(params)
    const pg = res as Paginated<MediaItem>
    items.value = pg.data
    total.value = pg.total
    currentPage.value = pg.current_page
    lastPage.value = pg.last_page
  } catch (e: any) {
    error.value = e?.message || 'Failed to load media.'
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await loadDirectories()
  await load()
})
watch([q, page, selectedDir], () => load())
watch(tab, () => {
  page.value = 1
  load()
})
watch(perPage, () => {
  page.value = 1
  load()
})

function close() {
  emit('close')
}

function choose(item: MediaItem) {
  selectedId.value = item.id
}

function confirmSelection() {
  const item = items.value.find(i => i.id === selectedId.value)
  if (item) emit('select', item)
}

async function onUploadChange(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  uploading.value = true
  dupPayload.value = null
  error.value = null
  try {
    const media = await upload(selectedDir.value || props.contextDir, file)
    // Prepend uploaded item
    items.value.unshift(media)
    selectedId.value = media.id
  } catch (err: any) {
    if (err?.code === 409) {
      dupPayload.value = err.payload
    } else {
      error.value = err?.message || 'Upload failed.'
    }
  } finally {
    uploading.value = false
    if (input) input.value = ''
  }
}

function triggerUpload() {
  hiddenFileInput.value?.click()
}

function useExistingFromDuplicate() {
  const d = dupPayload.value?.duplicate as MediaItem | undefined
  if (d) emit('select', d)
}

// New folder UI
const showNewFolder = ref(false)
const newFolderName = ref('')
const creatingFolder = ref(false)

function nestingLevel(path: string): number {
  const segs = path.split('/').filter(Boolean)
  return Math.max(0, segs.length - 1)
}

async function submitNewFolder() {
  const parent = selectedDir.value || props.contextDir
  const level = nestingLevel(parent)
  if (level >= allowNest.value) {
    error.value = `Cannot create folder here: exceeds nesting limit (${allowNest.value}).`
    return
  }
  if (!newFolderName.value.trim()) {
    error.value = 'Folder name cannot be empty.'
    return
  }
  creatingFolder.value = true
  try {
    const res = await createFolder(parent, newFolderName.value.trim())
    // Refresh directories and switch to the new folder
    await loadDirectories()
    if (res?.path) selectedDir.value = res.path
    showNewFolder.value = false
    newFolderName.value = ''
  } catch (e: any) {
    error.value = e?.message || 'Failed to create folder.'
  } finally {
    creatingFolder.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center">
    <div class="absolute inset-0 bg-black/40" @click="close" />
    <div class="relative z-10 w-[92vw] max-w-4xl rounded-lg border bg-card text-card-foreground shadow-xl">
      <div class="flex items-center justify-between border-b px-4 py-3">
        <div class="flex items-center gap-2">
          <h3 class="text-base font-semibold">Select media</h3>
          <div class="ml-2 inline-flex rounded-md border p-0.5">
            <button type="button" class="rounded-sm px-2 py-1 text-xs"
                    :class="tab === 'folder' ? 'bg-accent text-accent-foreground' : ''"
                    @click="tab = 'folder'">Folder</button>
            <button type="button" class="rounded-sm px-2 py-1 text-xs"
                    :class="tab === 'all' ? 'bg-accent text-accent-foreground' : ''"
                    @click="tab = 'all'">All</button>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <!-- Search in header -->
          <input
            v-model="q"
            type="search"
            placeholder="Search by name or type..."
            class="w-48 md:w-64 rounded-md border px-3 py-1.5 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-600"
            aria-label="Search media"
          />
          <button type="button" class="text-sm text-muted-foreground hover:text-foreground" @click="close">Close</button>
        </div>
      </div>
      <div class="flex flex-wrap items-center gap-3 border-b px-4 py-3">
        <select
          v-model="selectedDir"
          class="rounded-md border px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-blue-600"
        >
          <option v-for="p in dirsFlat" :key="p" :value="p">{{ p }}</option>
        </select>
        <div class="flex items-center gap-2">
          <button
            type="button"
            class="rounded border bg-background px-2 py-1 text-xs hover:bg-accent hover:text-accent-foreground"
            @click="showNewFolder = !showNewFolder"
          >
            New folder
          </button>
          <div v-if="showNewFolder" class="flex items-center gap-2">
            <input
              v-model="newFolderName"
              type="text"
              placeholder="Folder name"
              class="w-44 rounded-md border px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-blue-600"
              @keyup.enter="submitNewFolder"
            />
            <button type="button" class="rounded bg-blue-600 px-2 py-1 text-xs text-white hover:bg-blue-700 disabled:opacity-50" :disabled="creatingFolder" @click="submitNewFolder">Create</button>
          </div>
        </div>

        <input ref="hiddenFileInput" type="file" accept="image/*" class="sr-only" @change="onUploadChange" />
        <button
          type="button"
          class="rounded border bg-background px-3 py-1 text-sm hover:bg-accent hover:text-accent-foreground disabled:opacity-50"
          :disabled="uploading"
          @click="triggerUpload"
          aria-label="Upload image"
        >
          {{ uploading ? 'Uploading…' : `Upload${maxSizeLabel ? ` (Max ${maxSizeLabel})` : ''}` }}
        </button>
        <span class="text-xs text-muted-foreground">Target: {{ selectedDir || contextDir }}</span>

      </div>

      <div class="max-h-[70vh] overflow-auto p-4">
        <div v-if="error" class="mb-3 rounded border border-rose-300 bg-rose-50 p-2 text-sm text-rose-700 dark:border-rose-800 dark:bg-rose-900/30 dark:text-rose-200">
          {{ error }}
        </div>
        <div v-if="dupPayload" class="mb-3 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
          <div class="font-medium">Duplicate detected: {{ dupPayload?.duplicate?.original_name }}</div>
          <p class="mt-1">An identical file already exists. You can use the existing copy now.</p>
          <div class="mt-2 flex gap-2">
            <button type="button" class="rounded border bg-background px-3 py-1 text-sm hover:bg-accent hover:text-accent-foreground" @click="useExistingFromDuplicate">Use existing</button>
            <button type="button" class="rounded border px-3 py-1 text-sm opacity-50" disabled title="Not implemented yet">Keep both</button>
          </div>
        </div>

        <div v-if="loading" class="py-12 text-center text-sm text-muted-foreground">Loading…</div>
        <MediaGrid v-else :items="items" :selected-id="selectedId ?? undefined" @select="choose" />
      </div>

      <div class="flex items-center justify-between border-t px-4 py-3">
        <div class="flex items-center gap-3 text-xs text-muted-foreground">
          <span>Total: {{ total }}</span>
          <span>Page {{ currentPage }} of {{ lastPage }}</span>
          <label class="inline-flex items-center gap-1">Per page
            <select v-model.number="perPage" class="rounded-md border px-2 py-1 text-xs focus:outline-none focus:ring-2 focus:ring-blue-600">
              <option :value="10">10</option>
              <option :value="24">24</option>
              <option :value="48">48</option>
            </select>
          </label>
        </div>
        <div class="flex items-center gap-2">
          <button type="button" class="rounded border bg-background px-2 py-1 text-sm hover:bg-accent hover:text-accent-foreground disabled:opacity-50"
                  :disabled="page <= 1 || loading" @click="page = Math.max(1, page - 1)">Prev</button>
          <button type="button" class="rounded border bg-background px-2 py-1 text-sm hover:bg-accent hover:text-accent-foreground disabled:opacity-50"
                  :disabled="page >= lastPage || loading" @click="page = Math.min(lastPage, page + 1)">Next</button>
          <button type="button" class="rounded border bg-background px-3 py-1 text-sm hover:bg-accent hover:text-accent-foreground" @click="close">Cancel</button>
          <button type="button" class="rounded bg-blue-600 px-3 py-1 text-sm text-white hover:bg-blue-700 disabled:opacity-50" :disabled="!selectedId" @click="confirmSelection">Select</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
</style>
