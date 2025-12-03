# Media Manager (in‑repo module)

This folder captures a self‑contained snapshot of the in‑repo Media Manager implementation so you can:
- Understand what files are involved (backend + frontend)
- Copy/extract them to other apps or into a dedicated package later
- Follow installation and integration guides without hunting through the repo

The live, working code remains in the application under `app/`, `config/`, `resources/`, etc. This folder mirrors those files under `MediaManager/files/**` for convenience and long‑term documentation.

## Features (Snapshot)
- Configurable directories (roots with labels) + dynamic subfolder scanning
- Role‑guarded access per root (Spatie roles; configurable on/off)
- Uploads with SHA‑256 duplicate detection and decision UX (currently supports "Use existing")
- Memory‑aware thumbnailing for raster images with safety limits (64px, 256px JPEG)
- Explicit logging: dedupe hits, thumbnail preflight estimates, threshold/risk‑based skips, success timings
- Folder creation with sanitized names and enforced nesting depth
- Vue 3 modal picker with Folder/All tabs, search (name/type), pagination, per‑page selector, upload with CSRF, duplicate handling

## File map (where these live in the app)

Backend
- `config/media-manager.php`
- `database/migrations/2025_12_03_000100_create_media_table.php`
- `app/Models/Media.php`
- `app/Http/Controllers/MediaController.php`
- `app/Http/Requests/Media/ListMediaRequest.php`
- `app/Http/Requests/Media/StoreMediaRequest.php`
- `app/Services/MediaManager/HumanFileSize.php`
- `app/Services/MediaManager/MediaDirectoryScanner.php`
- Routes (see `routes/web.php`):
  - GET `/media`, GET `/media/directories`, POST `/media`, POST `/media/folders` (all behind `auth`)

Frontend
- `resources/js/composables/useMediaApi.ts`
- `resources/js/components/media/MediaPicker.vue`
- `resources/js/components/media/MediaGrid.vue`

This folder includes read‑only copies of those files under `MediaManager/files/…` for reference and package extraction.

## Installation (for a fresh app)
1) Composer dependencies
```
composer require intervention/image spatie/laravel-permission
```

2) Environment configuration (see `.env.example` in this folder)
- Copy the `MEDIA_*` keys into your app `.env`. Example defaults:
```
MEDIA_MAX_FILE_SIZE=5MB
MEDIA_ENFORCE_SPATIE=false
MEDIA_DISK=public
MEDIA_THUMB_MAX_PIXELS=40000000
MEDIA_THUMB_MAX_SIZE=20MB
```

3) Publish/enable storage link (if not already)
```
php artisan storage:link
```

4) Database migration
```
php artisan migrate
```

5) Routes
- Ensure the following routes exist under `auth` middleware:
```
Route::get('/media', [MediaController::class, 'index']);
Route::get('/media/directories', [MediaController::class, 'directories']);
Route::post('/media', [MediaController::class, 'store']);
Route::post('/media/folders', [MediaController::class, 'createFolder']);
```

6) Frontend CSRF meta tag
- Your base layout must include the Laravel CSRF meta tag so uploads succeed:
```
<meta name="csrf-token" content="{{ csrf_token() }}">
```

7) PHP memory limit (recommended for image processing)
- Set `memory_limit = 256M` (or higher) in your PHP config for reliable thumbnailing of modern phone photos.

## Configuration (`config/media-manager.php`)
- `max_file_size`: human‑readable limit (e.g., `500KB`, `5MB`, `1GB`).
- `allowed_directories`: whitelisted roots with labels; subdirs discovered dynamically.
- `scan_depth`: how deep the scanner explores for subdirectories.
- `allowed_folder_nest`: how many levels users can create under a root.
- `thumbnails`: sizes generated (square fit) as JPEG at quality per size.
- `thumbnail_max_pixels`, `thumbnail_max_filesize_bytes`: soft safety limits to skip thumbnails if too large.
- `disk`, `visibility`: storage disk and file visibility.
- `enforce_spatie_permission`, `permissions`: toggle role enforcement and per‑root role lists.

## Usage examples (high level)
- Vue form button opens the picker modal:
```
<MediaPicker :context-dir="'trainers'" @select="onMediaSelected" />
```
- On select, save the relative `path` (e.g., `trainers/avatar.jpg`) in your form and compute URL server‑side via `Storage::disk('public')->url($path)`.

See `USAGE.md` for concrete code examples (Trainer photo + Site logo).

## Extracting into a package later
- Treat `MediaManager/files/**` as your seed. Create a new package (e.g., `BookingHomse/Media`) and move:
  - Config → publishable config
  - Migration → publishable migration
  - Routes → route file within a ServiceProvider
  - Controllers/Requests/Models/Services → package namespace
  - Frontend assets → publishable `resources` or a small npm package

## Support & logging
- The controller logs rich diagnostics for uploads and thumbnails. See `storage/logs/laravel.log`.

If you need additional guidance or want me to automate package scaffolding, say the word and I’ll script it.
