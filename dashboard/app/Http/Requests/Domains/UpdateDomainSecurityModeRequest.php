<?php

namespace App\Http\Requests\Domains;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDomainSecurityModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'security_mode' => ['required', 'in:monitor,balanced,aggressive'],
        ];
    }
}
