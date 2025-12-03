<?php

namespace Jjdyo\MediaManager\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class ListMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'dir' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
