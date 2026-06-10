<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\OrderStatusMail;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::with('user');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('order_number', 'like', "%$s%")
                ->orWhere('shipping_name', 'like', "%$s%")
                ->orWhere('shipping_email', 'like', "%$s%"));
        }

        $orders = $query->latest()->paginate(20)->withQueryString();
        return view('admin.orders.index', compact('orders'));
    }

    public function show(Order $order)
    {
        $order->load('user', 'items.product', 'mpesaTransactions');
        return view('admin.orders.show', compact('order'));
    }

    public function updateStatus(Request $request, Order $order)
    {
        $request->validate([
            'status'         => 'required|in:pending,processing,shipped,delivered,cancelled,refunded',
            'payment_status' => 'required|in:pending,paid,failed,refunded',
        ]);

        $prevStatus = $order->status;

        $order->update([
            'status'         => $request->status,
            'payment_status' => $request->payment_status,
        ]);

        if ($prevStatus !== $request->status && $order->shipping_email) {
            try {
                Mail::to($order->shipping_email)->send(new OrderStatusMail($order->fresh()));
            } catch (\Exception) {}
        }

        return back()->with('success', 'Order status updated.');
    }

    public function destroy(Order $order)
    {
        $order->delete();

        return redirect()->route('admin.orders.index')
            ->with('success', 'Order ' . $order->order_number . ' has been deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        if ($request->boolean('delete_all')) {
            $count = Order::count();
            Order::query()->delete();
        } else {
            $ids = array_filter((array) $request->input('order_ids', []), 'is_numeric');
            if (empty($ids)) {
                return redirect()->route('admin.orders.index')
                    ->with('error', 'No orders selected.');
            }
            $count = Order::whereIn('id', $ids)->delete();
        }

        return redirect()->route('admin.orders.index')
            ->with('success', $count . ' order' . ($count === 1 ? '' : 's') . ' deleted.');
    }
}
