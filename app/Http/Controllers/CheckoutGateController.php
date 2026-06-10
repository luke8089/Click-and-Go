<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class CheckoutGateController extends Controller
{
    public function show()
    {
        // Already logged in and verified — skip the gate
        if (auth()->check()) {
            return redirect()->route('checkout.index');
        }

        return view('checkout.gate');
    }

    public function continueAsGuest()
    {
        session(['guest_checkout' => true]);
        return redirect()->route('checkout.index');
    }
}
