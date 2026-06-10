<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Category;

class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'total_orders'    => Order::count(),
            'total_revenue'   => Order::where('payment_status', 'paid')->sum('total'),
            'total_products'  => Product::count(),
            'total_customers' => User::where('role', 'customer')->count(),
            'pending_orders'  => Order::where('status', 'pending')->count(),
            'low_stock'       => Product::where('stock', '<=', 5)->where('is_active', true)->count(),
        ];

        $recentOrders    = Order::with('user')->latest()->take(10)->get();
        $topProducts     = Product::withCount('orderItems')->orderByDesc('order_items_count')->take(5)->get();
        $recentCustomers = User::where('role', 'customer')->latest()->take(5)->get();

        $monthlySales = Order::selectRaw('MONTH(created_at) as month, SUM(total) as total')
            ->where('payment_status', 'paid')
            ->whereYear('created_at', now()->year)
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return view('admin.dashboard', compact('stats', 'recentOrders', 'topProducts', 'recentCustomers', 'monthlySales'));
    }
}
