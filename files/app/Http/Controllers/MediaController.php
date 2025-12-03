<?php

namespace App\Http\Controllers;

use App\Http\Requests\Media\ListMediaRequest;
use App\Http\Requests\Media\StoreMediaRequest;
use App\Models\Media;
use App\Services\MediaManager\MediaDirectoryScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManagerStatic as Image;

class MediaController extends Controller
{
    public function index(ListMediaRequest $request): JsonResponse
    {
        // Listing/browsing is allowed for all authenticated users regardless of directory roles
        // so users can see and reuse media that is already public/accessible by URL.
        // We still require auth via the routes middleware.

        $q = Media::query();
        if ($dir = $request->string('dir')->toString()) {
            // Exact dir match; the `dir` column stores the directory the file lives in
            $q->where('dir', rtrim($dir, '/'));
        }
        if ($search = $request->string('q')->toString()) {
            $q->where(function ($sub) use ($search) {
                $sub->where('original_name', 'like', "%$search%")
                    ->orWhere('path', 'like', "%$search%")
                    ->orWhere('mime', 'like', "%$search%");
            });
        }
        $perPage = (int) ($request->input('per_page', 24));
        $items = $q->orderByDesc('id')->paginate($perPage);
        return response()->json($items);
    }

    public function directories(MediaDirectoryScanner $scanner): JsonResponse
    {
        $rootsConf = config('media-manager.allowed_directories', []);
        $roots = array_keys($rootsConf);
        $tree = $scanner->tree();
        $flat = $this->flattenTree($tree);

        return response()->json([
            'roots' => $roots,
            'labels' => array_map(fn($r)=>($rootsConf[$r]['label'] ?? $r), $roots),
            'tree' => $tree,
            'flat' => $flat,
            'config' => [
                'max_file_size' => (string) config('media-manager.max_file_size', '5MB'),
                'allowed_folder_nest' => (int) config('media-manager.allowed_folder_nest', 3),
                'scan_depth' => (int) config('media-manager.scan_depth', 3),
            ],
        ]);
    }

    public function store(StoreMediaRequest $request): JsonResponse
    {
        $user = $request->user();
        $dir = $this->normalizeAndValidateDir($request->string('dir')->toString());
        $this->authorizeDir($user, $dir, 'write');
        $verbose = (bool) config('media-manager.verbose_logging', false);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $disk = config('media-manager.disk', 'public');

        // Compute hash for dedupe
        $sha256 = hash_file('sha256', $file->getRealPath());
        $duplicate = Media::where('sha256', $sha256)->where('disk', $disk)->first();
        if ($duplicate) {
            // Return a 409 with options for client to decide
            if ($verbose) {
                Log::info('Media upload dedupe hit', [
                    'user_id' => optional($user)->id,
                    'dir' => $dir,
                    'disk' => $disk,
                    'duplicate_media_id' => $duplicate->id,
                    'duplicate_path' => $duplicate->path,
                    'mime' => $duplicate->mime,
                    'size_bytes' => $duplicate->size_bytes,
                ]);
            }
            return response()->json([
                'message' => 'Duplicate detected',
                'duplicate' => $duplicate,
                'options' => [
                    'replace_existing' => true,
                    'keep_both' => true,
                    'use_existing' => true,
                ],
            ], 409);
        }

        // Respect optional custom filename (base name only). Keep actual extension from upload to avoid mismatches.
        $requestedName = trim((string) $request->input('filename', ''));
        $originalName = $requestedName !== '' ? $requestedName : ($file->getClientOriginalName() ?: $file->getClientOriginalName());
        $ext = strtolower($file->getClientOriginalExtension());
        $safeBase = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
        if ($safeBase === '') { $safeBase = Str::random(8); }
        $filename = $safeBase . ($ext ? ".{$ext}" : '');

        // Ensure target subdir exists
        Storage::disk($disk)->makeDirectory($dir);

        // Avoid overwrite
        $targetPath = $dir . '/' . $filename;
        $i = 1;
        while (Storage::disk($disk)->exists($targetPath)) {
            $targetPath = $dir . '/' . $safeBase . "-{$i}." . $ext;
            $i++;
        }

        // Store original
        $storedPath = $file->storeAs($dir, basename($targetPath), $disk);

        // Collect metadata
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $size = (int) $file->getSize();
        $width = null; $height = null;
        $thumbs = [];

        // For raster images, attempt lightweight dimension read and guarded thumbnailing
        if ($ext !== 'svg' && $mime !== 'image/svg+xml') {
            $maxPixels = (int) config('media-manager.thumbnail_max_pixels', 40_000_000); // e.g., 40 MP
            $maxThumbFileSize = (int) config('media-manager.thumbnail_max_filesize_bytes', 20 * 1024 * 1024);

            // Try to get dimensions without fully decoding into Intervention first
            try {
                $sizeInfo = @getimagesize($file->getRealPath());
                if (is_array($sizeInfo)) {
                    $width = $sizeInfo[0] ?? null;
                    $height = $sizeInfo[1] ?? null;
                }
            } catch (\Throwable $e) {
                Log::warning('Media getimagesize failed', [
                    'error' => $e->getMessage(),
                    'path' => $file->getRealPath(),
                ]);
            }

            $pixelCount = ($width && $height) ? ($width * $height) : null;
            $shouldThumb = true;

            // Preflight memory estimation before invoking Intervention to avoid fatal OOMs
            try {
                $memLimitStr = ini_get('memory_limit');
                $memLimit = $this->parseIniBytes($memLimitStr);
                $currentUsage = function_exists('memory_get_usage') ? (int) memory_get_usage() : 0;
                if ($pixelCount) {
                    // Rough estimate: RGBA (4 bytes/px) decode buffer + working headroom (x1.5)
                    $estimatedDecode = (int) ($pixelCount * 4);
                    $estimatedPeak = (int) ($estimatedDecode * 1.5);
                    $projectedTotal = $currentUsage + $estimatedPeak;
                    if ($verbose) {
                        Log::info('Media thumbnail memory preflight', [
                            'user_id' => optional($user)->id,
                            'w' => $width, 'h' => $height, 'pixels' => $pixelCount,
                            'size_bytes' => $size,
                            'memory_limit' => $memLimitStr,
                            'memory_limit_bytes' => $memLimit,
                            'current_usage_bytes' => $currentUsage,
                            'estimated_decode_bytes' => $estimatedDecode,
                            'estimated_peak_bytes' => $estimatedPeak,
                            'projected_total_bytes' => $projectedTotal,
                        ]);
                    }
                    if ($memLimit > 0) {
                        // If projected usage exceeds 90% of limit, skip thumbnails to be safe
                        if ($projectedTotal > (int) floor($memLimit * 0.9)) {
                            $shouldThumb = false;
                            Log::warning('Media thumbnail skipped due to memory preflight risk', [
                                'user_id' => optional($user)->id,
                                'pixels' => $pixelCount,
                                'projected_total_bytes' => $projectedTotal,
                                'memory_limit_bytes' => $memLimit,
                            ]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Media thumbnail memory preflight failed', [
                    'error' => $e->getMessage(),
                ]);
            }
            if ($pixelCount && $pixelCount > $maxPixels) {
                $shouldThumb = false;
                if ($verbose) Log::info('Media thumbnail skipped due to pixel threshold', [
                    'w' => $width, 'h' => $height, 'pixels' => $pixelCount, 'maxPixels' => $maxPixels,
                ]);
            }
            if ($size > 0 && $size > $maxThumbFileSize) {
                $shouldThumb = false;
                if ($verbose) Log::info('Media thumbnail skipped due to file size threshold', [
                    'size_bytes' => $size, 'max' => $maxThumbFileSize,
                ]);
            }

            if ($shouldThumb) {
                $thumbStart = microtime(true);
                try {
                    // Create a memory-aware thumbnail pipeline:
                    // 1) Generate the largest configured thumbnail from the original once
                    // 2) Derive smaller thumbnails from the last generated file to reduce peak memory
                    $imgProbe = Image::make($file->getRealPath());
                    // If width/height were not set by getimagesize, read from Intervention
                    $width = $width ?? $imgProbe->width();
                    $height = $height ?? $imgProbe->height();
                    // Build and sort thumbnail definitions by descending target size
                    $thumbCfg = config('media-manager.thumbnails', []);
                    $defs = [];
                    foreach ($thumbCfg as $k => $def) {
                        [$w, $h, $q] = $def;
                        $defs[] = [
                            'key' => (string) $k,
                            'w' => (int) $w,
                            'h' => (int) $h,
                            'q' => (int) $q,
                            'max' => max((int) $w, (int) $h),
                        ];
                    }
                    // If misconfigured or empty, skip safely
                    if (!empty($defs)) {
                        usort($defs, function($a, $b){ return $b['max'] <=> $a['max']; });
                        // Start with the original image in memory and iteratively derive smaller ones
                        $currentImage = Image::make($file->getRealPath());
                        foreach ($defs as $i => $d) {
                            // Work on a clone to avoid mutating the source in-place before encoding
                            $work = clone $currentImage;
                            // Apply orientation if EXIF present
                            try { $work->orientate(); } catch (\Throwable $e) { /* ignore */ }
                            $work->fit($d['w'], $d['h'], function($c){});

                            $thumbRel = 'thumbnails/' . trim($dir, '/') . '/' . pathinfo($targetPath, PATHINFO_FILENAME) . "_{$d['key']}.jpg";
                            $this->ensureDirectory($disk, dirname($thumbRel));
                            Storage::disk($disk)->put($thumbRel, (string) $work->encode('jpg', $d['q']));
                            $thumbs[$d['key']] = $thumbRel;

                            // For next iteration, derive from this smaller image instance to lower memory footprint
                            $currentImage = $work;
                        }
                    }

                    // Free probe image as well
                    unset($imgProbe);
                    $elapsedMs = (int) round((microtime(true) - $thumbStart) * 1000);
                    if ($verbose) {
                        Log::info('Media thumbnails generated', [
                            'user_id' => optional($user)->id,
                            'dir' => $dir,
                            'path' => $storedPath,
                            'sizes' => array_keys($thumbs),
                            'elapsed_ms' => $elapsedMs,
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Media thumbnail generation failed', [
                        'error' => $e->getMessage(),
                        'mime' => $mime,
                        'size_bytes' => $size,
                        'path' => $file->getRealPath(),
                    ]);
                }
            }
        }

        $media = Media::create([
            'disk' => $disk,
            'dir' => $dir,
            'path' => $storedPath,
            'original_name' => $originalName,
            'ext' => $ext,
            'mime' => $mime,
            'size_bytes' => $size,
            'width' => $width,
            'height' => $height,
            'sha256' => $sha256,
            'thumbnails' => $thumbs,
            'visibility' => config('media-manager.visibility', 'public'),
            'created_by' => optional(Auth::user())->id,
        ]);

        return response()->json($media, 201);
    }

    public function createFolder(\Illuminate\Http\Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'parent_dir' => ['required', 'string'],
            'name' => ['required', 'string', 'max:64'],
        ]);
        $parent = $this->normalizeAndValidateDir((string) $validated['parent_dir']);
        $this->authorizeDir($user, $parent, 'write');

        $rawName = trim($validated['name']);
        // Sanitize segment: allow letters, numbers, dashes and underscores; collapse others to dash
        $segment = preg_replace('/[^A-Za-z0-9_\-]+/', '-', $rawName) ?: 'folder';
        $segment = trim($segment, '-_');
        if ($segment === '') { $segment = 'folder'; }

        $newPath = $parent . '/' . $segment;
        // Re-validate nest depth for the new path
        $this->normalizeAndValidateDir($newPath);

        $disk = config('media-manager.disk', 'public');
        if (!Storage::disk($disk)->exists($newPath)) {
            Storage::disk($disk)->makeDirectory($newPath);
        }

        Log::info('Media folder created', [
            'user_id' => optional($user)->id,
            'parent_dir' => $parent,
            'created' => $newPath,
        ]);

        // Return simple payload; client can refresh directories
        return response()->json([
            'path' => $newPath,
            'name' => basename($newPath),
        ], 201);
    }

    protected function normalizeAndValidateDir(string $dir): string
    {
        $dir = trim(str_replace('..', '', $dir), '/');
        $segments = explode('/', $dir);
        $root = $segments[0] ?? '';
        $allowed = array_keys(config('media-manager.allowed_directories', []));
        abort_unless(in_array($root, $allowed, true), 422, 'Invalid root directory');

        $allowedNest = (int) config('media-manager.allowed_folder_nest', 3);
        if (count($segments) - 1 > $allowedNest) {
            abort(422, 'Too deep: exceeds allowed_folder_nest');
        }
        return $dir;
    }

    protected function authorizeDir($user, ?string $dir, string $intent = 'read'): void
    {
        if (!$user) abort(403, 'Authentication required.');

        // Only enforce roles for write intents (upload/folder creation). Reads are allowed to any auth user.
        if ($intent !== 'write') {
            return;
        }

        if (!config('media-manager.enforce_spatie_permission')) return;

        $rolesByDir = config('media-manager.permissions', []);
        $root = $dir ? explode('/', trim($dir, '/'))[0] : null;
        $roles = $rolesByDir[$root] ?? $rolesByDir['*'] ?? [];
        if (empty($roles)) return; // everyone allowed

        foreach ($roles as $role) {
            if ($user->hasRole($role)) return;
        }
        \Log::warning('Media write denied by role policy', [
            'user_id' => optional($user)->id,
            'dir' => $dir,
            'root' => $root,
            'intent' => $intent,
            'required_roles' => $roles,
        ]);
        abort(response()->json([
            'message' => 'You do not have permission to upload to this directory.',
            'dir' => $dir,
            'root' => $root,
            'required_roles' => $roles,
        ], 403));
    }

    protected function ensureDirectory(string $disk, string $dir): void
    {
        if (!Storage::disk($disk)->exists($dir)) {
            Storage::disk($disk)->makeDirectory($dir);
        }
    }

    /**
     * Parse PHP ini shorthand into bytes. Supports values like "128M", "256K", "1G".
     * Returns -1 for unlimited or 0 if unknown.
     */
    protected function parseIniBytes(?string $val): int
    {
        if ($val === null || $val === '') return 0;
        $v = trim($val);
        if ($v === '-1') return -1;
        // If plain integer, assume bytes
        if (preg_match('/^\d+$/', $v)) return (int) $v;
        if (preg_match('/^(\d+)\s*([KMG])$/i', $v, $m)) {
            $num = (int) $m[1];
            $unit = strtoupper($m[2]);
            return match ($unit) {
                'K' => $num * 1024,
                'M' => $num * 1024 * 1024,
                'G' => $num * 1024 * 1024 * 1024,
                default => (int) $num,
            };
        }
        // Fallback: try to detect with B-suffixed
        if (preg_match('/^(\d+)\s*([KMG])B$/i', $v, $m)) {
            $num = (int) $m[1];
            $unit = strtoupper($m[2]);
            return match ($unit) {
                'K' => $num * 1024,
                'M' => $num * 1024 * 1024,
                'G' => $num * 1024 * 1024 * 1024,
                default => (int) $num,
            };
        }
        return 0;
    }

    /**
     * Flatten directory tree into list of path strings.
     * @param array $tree
     * @return array
     */
    protected function flattenTree(array $tree): array
    {
        $out = [];
        $walk = function($node) use (&$out, &$walk) {
            if (!isset($node['path'])) return;
            $out[] = $node['path'];
            if (!empty($node['children']) && is_array($node['children'])) {
                foreach ($node['children'] as $child) { $walk($child); }
            }
        };
        foreach ($tree as $n) { $walk($n); }
        return $out;
    }
}
