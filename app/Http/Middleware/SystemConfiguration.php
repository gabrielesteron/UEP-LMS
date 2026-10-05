<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;

class SystemConfiguration
{
    public function handle(Request $request, Closure $next)
    {
        $settings = Setting::whereIn('key', ['lms_name', 'institution_name', 'lms_show_advanced_features'])->pluck('value', 'key');
        foreach (['lms_name', 'institution_name'] as $key) {
            if ($settings->has($key)) {
                config(['lms.'.$key => $settings[$key]]);
            }
        }
        if ($settings->has('lms_show_advanced_features')) {
            config(['lms.show_advanced_features' => $settings['lms_show_advanced_features'] === '1']);
        }
        return $next($request);
    }
}
