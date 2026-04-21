<?php

namespace App\Http\Requests\Logs;

use Illuminate\Foundation\Http\FormRequest;

class AllowIpFromLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ip' => ['required', 'ip'],
            'domain' => ['required', 'string', 'max:255'],
        ];
    }
}
