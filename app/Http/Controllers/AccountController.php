<?php

namespace App\Http\Controllers;

use App\Mail\EmailChangedMail;
use App\Mail\OrderStatusMail;
use App\Mail\PasswordChangedMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class AccountController extends Controller
{
    public function dashboard()
    {
        $user   = auth()->user();
        $orders = $user->orders()->with('items')->latest()->take(5)->get();
        return view('account.dashboard', compact('user', 'orders'));
    }

    public function profile()
    {
        return view('account.profile', ['user' => auth()->user()]);
    }

    public function updateProfile(Request $request)
    {
        $user           = auth()->user();
        $emailChanging  = $request->input('email') !== $user->email;

        $rules = [
            'name'    => ['required', 'string', 'min:2', 'max:100', 'regex:/^[\pL\s\'\-\.]+$/u'],
            'email'   => ['required', 'email:rfc', 'max:150', 'unique:users,email,' . $user->id],
            'phone'   => ['nullable', 'string', 'max:20', 'regex:/^[+\d][\d\s\-\(\)]{5,18}\d$/'],
            'city'    => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
        ];

        // Require current password when changing email
        if ($emailChanging && $user->password) {
            $rules['current_password'] = ['required'];
        }

        $validated = $request->validate($rules, [
            'name.regex'              => 'Name may only contain letters, spaces, hyphens, and apostrophes.',
            'phone.regex'             => 'Enter a valid phone number (e.g. +254 7XX XXX XXX).',
            'current_password.required' => 'Please enter your current password to change your email address.',
        ]);

        // Verify the password is correct before allowing email change
        if ($emailChanging && $user->password) {
            if (!Hash::check($request->current_password, $user->password)) {
                return back()->withErrors(['current_password' => 'Password is incorrect.']);
            }
        }

        $oldEmail = $user->email;

        $validated['name']    = strip_tags($validated['name']);
        $validated['city']    = isset($validated['city'])    ? strip_tags($validated['city'])    : null;
        $validated['address'] = isset($validated['address']) ? strip_tags($validated['address']) : null;
        unset($validated['current_password']);

        $user->update($validated);

        // Alert the old address so the real owner knows about the change
        if ($emailChanging) {
            try {
                Mail::to($oldEmail)->send(new EmailChangedMail(
                    user:     $user,
                    oldEmail: $oldEmail,
                    newEmail: $user->email,
                    time:     now()->format('M d, Y \a\t h:i A'),
                ));
            } catch (\Exception) {}
        }

        return back()->with('success', 'Profile updated successfully.');
    }

    public function updatePassword(Request $request)
    {
        $user = auth()->user();

        $request->validate([
            'current_password' => ['required'],
            'password'         => [
                'required', 'string', 'min:8', 'max:128', 'confirmed',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
            ],
        ], [
            'password.regex' => 'Password must include at least one uppercase letter, one lowercase letter, and one number.',
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }

        if (Hash::check($request->password, $user->password)) {
            return back()->withErrors(['password' => 'New password must be different from your current password.']);
        }

        $user->update(['password' => Hash::make($request->password)]);

        try {
            Mail::to($user->email)->send(new PasswordChangedMail(
                user: $user,
                time: now()->format('M d, Y \a\t h:i A'),
            ));
        } catch (\Exception) {}

        return back()->with('success', 'Password updated successfully.');
    }

    public function orders(Request $request)
    {
        $status  = $request->query('status');
        $allowed = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];

        $query = auth()->user()->orders()->with('items', 'mpesaTransactions')->latest();

        if ($status && in_array($status, $allowed)) {
            $query->where('status', $status);
        } else {
            $status = null;
        }

        $orders = $query->paginate(6)->withQueryString();

        return view('account.orders', compact('orders', 'status'));
    }

    public function showOrder(\App\Models\Order $order)
    {
        $this->claimGuestOrder($order);
        abort_unless($order->user_id === auth()->id(), 403);
        $order->load('items.product', 'mpesaTransactions');
        return view('account.order-detail', compact('order'));
    }

    public function receipt(\App\Models\Order $order)
    {
        $this->claimGuestOrder($order);
        abort_unless($order->user_id === auth()->id(), 403);
        $order->load('items.product');

        $logoPath = public_path('images/logo.jpeg');
        $logoSrc  = file_exists($logoPath)
            ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($logoPath))
            : '';

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('account.order-receipt', compact('order', 'logoSrc'))
            ->setPaper('a4', 'portrait');

        return $pdf->download($order->order_number . '.pdf');
    }

    /**
     * If this is an unclaimed guest order whose shipping email matches the logged-in user,
     * silently assign it so the ownership check that follows will pass.
     */
    private function claimGuestOrder(\App\Models\Order $order): void
    {
        if ($order->user_id !== null) return;
        if ($order->shipping_email !== auth()->user()->email) return;

        $order->update(['user_id' => auth()->id()]);
        $order->user_id = auth()->id(); // keep the in-memory model in sync
    }

    public function cancelOrder(\App\Models\Order $order)
    {
        $this->claimGuestOrder($order);
        abort_unless($order->user_id === auth()->id(), 403);

        if (!in_array($order->status, ['pending', 'processing'])) {
            return back()->with('error', 'This order can no longer be cancelled.');
        }

        $order->update(['status' => 'cancelled']);

        if ($order->shipping_email) {
            try {
                Mail::to($order->shipping_email)->send(new OrderStatusMail($order->fresh()));
            } catch (\Exception) {}
        }

        return back()->with('success', 'Order ' . $order->order_number . ' has been cancelled.');
    }
}
