<?php

namespace App\Http\Requests;

use App\Services\Catalog;
use Illuminate\Foundation\Http\FormRequest;

class CatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return Catalog::rules($this->route('resource'), $this->route('id') ? (int) $this->route('id') : null);
    }
}
