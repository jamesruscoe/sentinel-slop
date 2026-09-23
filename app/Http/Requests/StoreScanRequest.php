<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('scan', $this->route('repository')) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'model' => ['nullable', 'string', Rule::in((array) config('sentinel.synthesis.models', []))],
        ];
    }

    public function model(): ?string
    {
        $model = $this->validated('model');

        return is_string($model) && $model !== '' ? $model : null;
    }
}
