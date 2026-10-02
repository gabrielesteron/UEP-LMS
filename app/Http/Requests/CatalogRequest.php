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

    public function attributes(): array
    {
        [, $fields] = Catalog::definition($this->route('resource'));

        return collect($fields)->mapWithKeys(fn ($type, $field) => [$field => Catalog::fieldLabel($field, $this->route('resource'))])->all();
    }
}
