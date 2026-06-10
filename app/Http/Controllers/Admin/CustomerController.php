<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = User::where('role', 'customer');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%"));
        }

        $customers = $query->withCount('orders')->latest()->paginate(20)->withQueryString();
        return view('admin.customers.index', compact('customers'));
    }

    public function show(User $customer)
    {
        abort_unless($customer->role === 'customer', 404);
        $customer->load(['orders' => fn($q) => $q->latest()->take(10)]);
        return view('admin.customers.show', compact('customer'));
    }

    public function destroy(User $customer)
    {
        abort_unless($customer->role === 'customer', 403);
        $customer->delete();

        return redirect()->route('admin.customers.index')
            ->with('success', $customer->name . ' has been deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        if ($request->boolean('delete_all')) {
            $count = User::where('role', 'customer')->count();
            User::where('role', 'customer')->delete();
        } else {
            $ids = array_filter((array) $request->input('customer_ids', []), 'is_numeric');
            if (empty($ids)) {
                return redirect()->route('admin.customers.index')
                    ->with('error', 'No customers selected.');
            }
            $count = User::where('role', 'customer')->whereIn('id', $ids)->delete();
        }

        return redirect()->route('admin.customers.index')
            ->with('success', $count . ' customer' . ($count === 1 ? '' : 's') . ' deleted.');
    }
}
