<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index()
    {
        $settings = [
            'free_shipping_bar_enabled'  => Setting::get('free_shipping_bar_enabled', '1'),
            'free_shipping_threshold'    => Setting::get('free_shipping_threshold', '10000'),
            'direct_delivery_enabled'    => Setting::get('direct_delivery_enabled', '1'),
            'coupons_enabled'            => Setting::get('coupons_enabled', '1'),
            'admin_email'                => Setting::get('admin_email', config('mail.from.address')),
        ];

        return view('admin.settings.index', compact('settings'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'free_shipping_threshold' => 'required|numeric|min:0',
            'admin_email'             => 'nullable|email:rfc|max:150',
        ]);

        Setting::set('free_shipping_bar_enabled', $request->boolean('free_shipping_bar_enabled') ? '1' : '0');
        Setting::set('free_shipping_threshold',   $request->input('free_shipping_threshold'));
        Setting::set('direct_delivery_enabled',   $request->boolean('direct_delivery_enabled') ? '1' : '0');
        Setting::set('coupons_enabled',           $request->boolean('coupons_enabled') ? '1' : '0');

        if ($request->filled('admin_email')) {
            Setting::set('admin_email', strtolower(trim($request->input('admin_email'))));
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Settings saved successfully.');
    }
}
