<?php

namespace App\Services;

use Illuminate\Http\Request;

class Files
{
    public const RULE = 'file|mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,zip,jpg,jpeg,png,txt|max:20480';

    public static function upload(Request $r, string $field = 'attachment'): array
    {
        if (! $r->hasFile($field)) {
            return [];
        }

        return ['path' => $r->file($field)->store('class-files', 'local'), 'original_name' => basename($r->file($field)->getClientOriginalName())];
    }
}
