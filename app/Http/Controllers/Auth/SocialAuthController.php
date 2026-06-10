<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\LoginAlertMail;
use App\Mail\WelcomeMail;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Exception $e) {
            return redirect()->route('login')->withErrors(['email' => 'Google sign-in failed. Please try again.']);
        }

        $user      = User::where('google_id', $googleUser->getId())->first();
        $isNewUser = false;

        if (!$user) {
            $user = User::where('email', $googleUser->getEmail())->first();

            if ($user) {
                $user->google_id         = $googleUser->getId();
                $user->email_verified_at = $user->email_verified_at ?? now();
                $user->save();
            } else {
                $user = User::create([
                    'name'      => $googleUser->getName(),
                    'email'     => $googleUser->getEmail(),
                    'google_id' => $googleUser->getId(),
                    'password'  => null,
                ]);
                // role and email_verified_at are not mass-assignable — set directly
                $user->role              = 'customer';
                $user->email_verified_at = now();
                $user->save();
                $isNewUser = true;
            }
        }

        // Capture guest session ID before login regenerates the session
        $guestSessionId = session()->getId();

        Auth::login($user, true);

        // Send welcome email to brand-new Google users; login alert to returning users
        try {
            if ($isNewUser) {
                Mail::to($user->email)->send(new WelcomeMail($user));
            } else {
                Mail::to($user->email)->send(new LoginAlertMail(
                    user:      $user,
                    ip:        request()->ip(),
                    userAgent: request()->userAgent() ?? 'Unknown',
                    time:      now()->format('M d, Y \a\t h:i A'),
                ));
            }
        } catch (\Exception) {}

        // Merge guest cart into the authenticated user's cart
        $sessionItems = CartItem::where('session_id', $guestSessionId)->get();
        foreach ($sessionItems as $item) {
            $userItem = CartItem::firstOrNew([
                'user_id'    => $user->id,
                'product_id' => $item->product_id,
            ]);
            $userItem->quantity = ($userItem->quantity ?? 0) + $item->quantity;
            $userItem->save();
            $item->delete();
        }

        if ($user->isAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        // Link a guest order to this account if one was placed before signing in via Google
        if ($prefill = session('guest_order_prefill')) {
            if (!empty($prefill['order_id'])) {
                Order::where('id', $prefill['order_id'])
                     ->whereNull('user_id')
                     ->update(['user_id' => $user->id]);
            }
            session()->forget('guest_order_prefill');
            return redirect()->route('account.orders.show', $prefill['order_id'])
                             ->with('success', 'Your guest order has been linked to your account. You can now track it here.');
        }

        return redirect()->intended(route('home'));
    }
}
