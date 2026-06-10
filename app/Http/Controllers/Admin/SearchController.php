<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ContactMessage;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function search(Request $request)
    {
        $q = trim($request->get('q', ''));

        if (strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $like    = '%' . $q . '%';
        $results = [];

        // Products — name, sku, brand, part_number
        Product::where(function ($q2) use ($like) {
                $q2->where('name', 'like', $like)
                   ->orWhere('sku', 'like', $like)
                   ->orWhere('brand', 'like', $like)
                   ->orWhere('part_number', 'like', $like);
            })
            ->limit(4)
            ->get(['name', 'sku', 'part_number', 'slug', 'is_active'])
            ->each(function ($p) use (&$results) {
                $sub = $p->part_number ? 'Part#: ' . $p->part_number : ($p->sku ? 'SKU: ' . $p->sku : ($p->is_active ? 'Active' : 'Inactive'));
                $results[] = [
                    'type'  => 'Product',
                    'label' => $p->name,
                    'sub'   => $sub,
                    'url'   => route('admin.products.edit', $p->slug),
                    'icon'  => 'fa-box',
                    'color' => '#F7941D',
                ];
            });

        // Orders — order number, customer name/email
        Order::where('order_number', 'like', $like)
            ->orWhere('shipping_name', 'like', $like)
            ->orWhere('shipping_email', 'like', $like)
            ->limit(4)
            ->get(['id', 'order_number', 'shipping_name', 'status'])
            ->each(function ($o) use (&$results) {
                $results[] = [
                    'type'  => 'Order',
                    'label' => $o->order_number,
                    'sub'   => $o->shipping_name . ' · ' . ucfirst($o->status),
                    'url'   => route('admin.orders.show', $o->id),
                    'icon'  => 'fa-receipt',
                    'color' => '#1565C0',
                ];
            });

        // Customers (role = user) — name, email
        User::where('role', 'user')
            ->where(function ($q2) use ($like) {
                $q2->where('name', 'like', $like)
                   ->orWhere('email', 'like', $like);
            })
            ->limit(4)
            ->get(['id', 'name', 'email'])
            ->each(function ($c) use (&$results) {
                $results[] = [
                    'type'  => 'Customer',
                    'label' => $c->name,
                    'sub'   => $c->email,
                    'url'   => route('admin.customers.show', $c->id),
                    'icon'  => 'fa-user',
                    'color' => '#8b5cf6',
                ];
            });

        // Admins (role = admin) — name, email
        User::where('role', 'admin')
            ->where(function ($q2) use ($like) {
                $q2->where('name', 'like', $like)
                   ->orWhere('email', 'like', $like);
            })
            ->limit(3)
            ->get(['id', 'name', 'email'])
            ->each(function ($a) use (&$results) {
                $results[] = [
                    'type'  => 'Admin',
                    'label' => $a->name,
                    'sub'   => $a->email,
                    'url'   => route('admin.admins.index'),
                    'icon'  => 'fa-user-shield',
                    'color' => '#0891b2',
                ];
            });

        // Categories — name
        Category::where('name', 'like', $like)
            ->limit(4)
            ->get(['name', 'slug', 'is_active'])
            ->each(function ($cat) use (&$results) {
                $results[] = [
                    'type'  => 'Category',
                    'label' => $cat->name,
                    'sub'   => $cat->is_active ? 'Active' : 'Inactive',
                    'url'   => route('admin.categories.edit', $cat->slug),
                    'icon'  => 'fa-tags',
                    'color' => '#16a34a',
                ];
            });

        // Coupons — code, description
        Coupon::where('code', 'like', $like)
            ->orWhere('description', 'like', $like)
            ->limit(4)
            ->get(['id', 'code', 'type', 'value', 'is_active'])
            ->each(function ($coupon) use (&$results) {
                $results[] = [
                    'type'  => 'Coupon',
                    'label' => $coupon->code,
                    'sub'   => $coupon->getTypeLabel() . ' · ' . ($coupon->is_active ? 'Active' : 'Inactive'),
                    'url'   => route('admin.coupons.edit', $coupon->id),
                    'icon'  => 'fa-tag',
                    'color' => '#db2777',
                ];
            });

        // Contact messages — name, email, subject
        ContactMessage::where('name', 'like', $like)
            ->orWhere('email', 'like', $like)
            ->orWhere('subject', 'like', $like)
            ->limit(4)
            ->get(['id', 'name', 'email', 'subject', 'is_read'])
            ->each(function ($msg) use (&$results) {
                $results[] = [
                    'type'  => 'Message',
                    'label' => $msg->subject ?: $msg->name,
                    'sub'   => $msg->name . ' · ' . $msg->email,
                    'url'   => route('admin.contact-messages.index'),
                    'icon'  => 'fa-envelope',
                    'color' => '#7c3aed',
                ];
            });

        return response()->json(['results' => $results]);
    }
}
