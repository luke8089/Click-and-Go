<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class ProfileController extends Controller
{
    public function show()
    {
        return view('admin.profile', ['user' => auth()->user()]);
    }

    public function update(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'name'  => ['required', 'string', 'min:2', 'max:100', 'regex:/^[\pL\s\'\-\.]+$/u'],
            'email' => ['required', 'email:rfc', 'max:150', 'unique:users,email,' . $user->id],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[+\d][\d\s\-\(\)]{5,18}\d$/'],
        ], [
            'name.regex'  => 'Name may only contain letters, spaces, hyphens, and apostrophes.',
            'phone.regex' => 'Enter a valid phone number.',
        ]);

        // Explicit field list — never spread $validated directly to avoid unintended fields
        $user->update([
            'name'  => strip_tags($validated['name']),
            'email' => strtolower(trim($validated['email'])),
            'phone' => $validated['phone'] ?? null,
        ]);

        return back()->with('success', 'Profile updated successfully.');
    }

    public function updatePassword(Request $request)
    {
        $user = auth()->user();

        $request->validate([
            // max:128 prevents bcrypt CPU-DoS via extremely long strings
            'current_password' => ['required', 'string', 'max:128'],
            'password'         => [
                'required', 'string', 'min:10', 'max:128', 'confirmed',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
                'regex:/[^a-zA-Z0-9]/',
            ],
        ], [
            'password.min'   => 'Admin password must be at least 10 characters.',
            'password.regex' => 'Password must include uppercase, lowercase, a number, and a special character (e.g. @, !, #).',
        ]);

        // Never include raw passwords in withInput() — exclude them from the flash
        $safeInput = $request->except(['current_password', 'password', 'password_confirmation']);

        if (! Hash::check($request->current_password, $user->password)) {
            return back()
                ->withErrors(['current_password' => 'Current password is incorrect.'])
                ->withInput($safeInput);
        }

        if (Hash::check($request->password, $user->password)) {
            return back()
                ->withErrors(['password' => 'New password must be different from your current one.'])
                ->withInput($safeInput);
        }

        $user->update(['password' => Hash::make($request->password)]);

        Log::info('Admin password changed', ['user_id' => $user->id, 'ip' => $request->ip()]);

        return back()->with('password_success', 'Password updated successfully.');
    }
}
