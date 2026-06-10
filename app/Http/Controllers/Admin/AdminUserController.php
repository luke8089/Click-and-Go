<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AdminUserController extends Controller
{
    public function index()
    {
        $admins = User::where('role', 'admin')
            ->orderBy('created_at', 'asc')
            ->get();

        $users = User::where('role', 'customer')
            ->orderBy('name', 'asc')
            ->get(['id', 'name', 'email', 'created_at']);

        return view('admin.admins.index', compact('admins', 'users'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:100'],
            'email'    => ['required', 'email:rfc', 'max:150', 'unique:users,email'],
            'password' => ['required', 'max:128', 'confirmed',
                           Password::min(10)->letters()->mixedCase()->numbers()->symbols()],
        ]);

        $user = User::create([
            'name'     => strip_tags(trim($data['name'])),
            'email'    => strtolower(trim($data['email'])),
            'password' => Hash::make($data['password']),
        ]);

        $user->role = 'admin';
        $user->save();

        return redirect()->route('admin.admins.index')
            ->with('success', 'Admin account created for ' . $user->name . '.');
    }

    public function promote(User $user)
    {
        if ($user->role === 'admin') {
            return redirect()->route('admin.admins.index')
                ->with('error', $user->name . ' is already an admin.');
        }

        $user->role = 'admin';
        $user->save();

        return redirect()->route('admin.admins.index')
            ->with('success', $user->name . ' has been promoted to admin.');
    }

    public function destroy(User $admin)
    {
        if ($admin->id === auth()->id()) {
            return redirect()->route('admin.admins.index')
                ->with('error', 'You cannot remove your own admin access.');
        }

        $admin->role = 'customer';
        $admin->save();

        return redirect()->route('admin.admins.index')
            ->with('success', $admin->name . ' has been removed from admins.');
    }
}
