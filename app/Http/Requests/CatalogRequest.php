<?php

namespace App\Http\Requests;

use App\Services\Catalog;
use Illuminate\Foundation\Http\FormRequest;

class CatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('resource') === 'users'
            ? (bool) $this->user()?->isSuperAdmin()
            : (bool) $this->user()?->isAcademicAdmin();
    }

    public function rules(): array
    {
        return Catalog::rules($this->route('resource'), $this->route('id') ? (int) $this->route('id') : null, $this->all());
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->route('resource') === 'subjects' && ! $validator->errors()->any()
                && $this->filled('lecture_units') && abs((float) $this->units - (float) $this->lecture_units - (float) $this->laboratory_units) > 0.0001) {
                $validator->errors()->add('units', 'Total units must equal lecture units plus laboratory units.');
            }
        });
    }

    public function attributes(): array
    {
        [, $fields] = Catalog::definition($this->route('resource'));

        return collect($fields)->mapWithKeys(fn ($type, $field) => [$field => Catalog::fieldLabel($field, $this->route('resource'))])->all();
    }
}
