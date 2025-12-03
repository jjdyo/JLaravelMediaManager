<?php

namespace Jjdyo\MediaManager\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;
use Jjdyo\MediaManager\Services\MediaManager\HumanFileSize;
use function config;

class StoreMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        $maxBytes = HumanFileSize::parseToBytes(config('media-manager.max_file_size', '5MB'));
        $allowSvg = (bool) config('media-manager.allow_svg', true);
        $mimes = ['image/png','image/jpeg','image/webp'];
        if ($allowSvg) { $mimes[] = 'image/svg+xml'; }

        return [
            'dir' => ['required', 'string'], // validated in controller against allowed roots + nesting
            'file' => ['required', 'file', 'mimetypes:'.implode(',', $mimes), 'max:'.($maxBytes > 0 ? (int) ceil($maxBytes/1024) : 5120)],
            // Optional custom filename; sanitized server-side
            'filename' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        $max = (string) config('media-manager.max_file_size', '5MB');
        return [
            'file.max' => "The image exceeds the maximum allowed size of {$max}.",
            'file.mimetypes' => 'Unsupported image type. Allowed types: PNG, JPEG, WEBP' . (config('media-manager.allow_svg', true) ? ', SVG' : '' ) . '.',
            'file.required' => 'Please choose an image to upload.',
        ];
    }
}
