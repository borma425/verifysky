<?php

namespace App\Http\Requests\Logs;

use Illuminate\Foundation\Http\FormRequest;

class FilterSecurityLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_type' => ['nullable', 'string', 'max:60'],
            'domain_name' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['nullable', 'ip'],
            'include_archived' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
