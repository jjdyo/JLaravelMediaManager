<?php

namespace App\Services\MediaManager;

use Illuminate\Support\Facades\Storage;

class MediaDirectoryScanner
{
    public function tree(): array
    {
        $disk = config('media-manager.disk', 'public');
        $roots = array_keys(config('media-manager.allowed_directories', []));
        $depth = (int) config('media-manager.scan_depth', 3);
        $out = [];
        foreach ($roots as $root) {
            $out[] = $this->scanNode($disk, $root, $depth);
        }
        return $out;
    }

    protected function scanNode(string $disk, string $path, int $depth): array
    {
        $node = [
            'name' => basename($path),
            'path' => $path,
            'children' => [],
        ];
        if ($depth <= 0) return $node;
        $dirs = Storage::disk($disk)->directories($path);
        foreach ($dirs as $dir) {
            $node['children'][] = $this->scanNode($disk, $dir, $depth - 1);
        }
        return $node;
    }
}
