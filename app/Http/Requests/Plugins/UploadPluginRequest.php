<?php

namespace App\Http\Requests\Plugins;

use Illuminate\Foundation\Http\FormRequest;

class UploadPluginRequest extends FormRequest
{
    /**
     * Validation rules for plugin package uploads.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'plugin' => ['required', 'file', 'mimes:zip', 'max:'.((int) (config('plugin-market.max_zip_size') / 1024))],
        ];
    }

    /**
     * Human-readable attribute names.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'plugin' => '插件包',
        ];
    }
}
