# Media Manager — Usage examples

This guide shows how to integrate the Media Manager in a Laravel app after installing the Composer package. It covers backend routes, configuration, Vue integration, validation, controller snippets, and client API usage.

## Backend endpoints (auto-registered, under auth)

```
Route::get('/media', [MediaController::class, 'index'])->name('media.index');
Route::get('/media/directories', [MediaController::class, 'directories'])->name('media.directories');
Route::post('/media', [MediaController::class, 'store'])->name('media.store');
Route::post('/media/folders', [MediaController::class, 'createFolder'])->name('media.folders.create');
```

Note: The package loads these routes for you. Ensure they live behind your `auth` middleware (the package groups them under `auth`).

## Configuring directories and permissions

`config/media-manager.php` (excerpt):
```
return [
  'max_file_size' => env('MEDIA_MAX_FILE_SIZE', '5MB'),
  'allowed_directories' => [
    'logos' => ['label' => 'Brand Logos'],
    'trainers' => ['label' => 'Trainer Media'],
    'horses' => ['label' => 'Horse Media'],
    'timeslots' => ['label' => 'Timeslot Media'],
    'misc' => ['label' => 'Miscellaneous'],
  ],
  'scan_depth' => 3,
  'allowed_folder_nest' => 3,
  'thumbnails' => [ '64' => [64,64,80], '256' => [256,256,80] ],
  'thumbnail_max_pixels' => env('MEDIA_THUMB_MAX_PIXELS', 40000000),
  'thumbnail_max_filesize_bytes' => env('MEDIA_THUMB_MAX_SIZE', 20*1024*1024),
  'disk' => env('MEDIA_DISK', 'public'),
  'visibility' => 'public',
  'enforce_spatie_permission' => env('MEDIA_ENFORCE_SPATIE', false),
  'permissions' => [
    'logos' => ['admin', 'marketing'],
    'trainers' => [],
    '*' => ['admin'],
  ],
];
```

If `enforce_spatie_permission` is true, only users with roles listed for a root (or `*`) can access that directory. An empty array allows all authenticated users.

## Vue integration (Trainer photo)

1) Add the picker to your page and handle selection.

```
<script setup lang="ts">
import { ref } from 'vue'
// Import from published vendor assets (after running vendor:publish for assets)
import MediaPicker from '@/vendor/media-manager/components/media/MediaPicker.vue'

const showPicker = ref(false)
const form = useForm({ name: '', photo: null as File | null, photo_path: null as string | null })
const previewUrl = ref<string | null>(null)

function onMediaSelected(media: { path: string; url: string; thumbnails_urls?: Record<string, string> }) {
  form.photo_path = media.path
  form.photo = null as any
  previewUrl.value = media.thumbnails_urls?.['256'] ?? media.url
  showPicker.value = false
}
</script>

<template>
  <button type="button" @click="showPicker = true">Choose from library…</button>
  <MediaPicker v-if="showPicker" :context-dir="'trainers'" @close="showPicker = false" @select="onMediaSelected" />
</template>
```

2) Backend form requests accept `photo_path` alongside `photo`:

```
// StoreTrainerRequest / UpdateTrainerRequest
'photo' => ['nullable', 'image', 'max:5120'],
'photo_path' => ['nullable', 'string', 'max:512'],
```

3) Controller prioritizes `photo_path` over uploaded file (if provided):

```
$photoPath = $data['photo_path'] ?? null;
if (!$photoPath && $request->hasFile('photo')) {
  $photoPath = $request->file('photo')->store('trainers', 'public');
}
```

4) Render URLs dynamically:

```
<img :src="trainer.photo_path ? `/storage/${trainer.photo_path}` : placeholder" />
```

## Vue integration (Site logo)
- Use the picker with `context-dir="logos"`.
- Store only the relative path (e.g., `logos/app-brand.png`). Compute the URL with `Storage::disk('public')->url($path)` or via an accessor.

## Client API (useMediaApi.ts)

```
// Import composable from the published vendor path
import useMediaApi from '@/vendor/media-manager/composables/useMediaApi'

const { list, directories, upload, createFolder } = useMediaApi()

await directories() // { roots, labels, tree, flat, config }
await list({ dir: 'trainers', q: 'headshot', page: 1, per_page: 24 })
try {
  await upload('trainers', file)
} catch (e: any) {
  if (e.code === 409) { /* duplicate detected */ }
  if (e.code === 422) { /* show server validation messages */ }
}
await createFolder('trainers', 'headshots-2025')
```

## CSRF & Errors
- Ensure your layout includes `<meta name="csrf-token" content="{{ csrf_token() }}">` so uploads work.
- The upload API surfaces 422 validation messages and 409 duplicate payloads to the UI.

## Memory & thumbnails
- For modern phone photos, set PHP `memory_limit` to 256M+.
- The thumbnail pipeline generates the largest size first, then derives smaller sizes, reducing peak memory.
- You can adjust `MEDIA_THUMB_MAX_PIXELS` and `MEDIA_THUMB_MAX_SIZE` if your environment is memory‑constrained.

## Backfill and ingestion (optional)
- If you also upload images outside the Media Manager, add an ingestion step or write a small Artisan command to index existing disk files into the `media` table and generate thumbnails.

## Quick verification checklist
- Be logged in and visit `/media` — directories and files should render
- Try uploading the same file twice — the second attempt should trigger duplicate handling (409)
- Create nested folders — names are sanitized and depth is limited by config
- Toggle `MEDIA_ENFORCE_SPATIE=true` and verify access is limited per root roles
