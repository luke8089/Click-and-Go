<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DeliveryServiceController extends Controller
{
    public function index()
    {
        $services = DeliveryService::orderBy('sort_order')->orderBy('name')->get();
        return view('admin.delivery-services.index', compact('services'));
    }

    public function create()
    {
        return view('admin.delivery-services.form');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'  => 'required|string|max:100',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp,svg|max:2048',
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('delivery-services', 'public');
        }

        DeliveryService::create([
            'name'      => $request->name,
            'image'     => $imagePath,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('admin.delivery-services.index')
            ->with('success', 'Delivery service added.');
    }

    public function edit(DeliveryService $deliveryService)
    {
        $service = $deliveryService;
        return view('admin.delivery-services.form', compact('service'));
    }

    public function update(Request $request, DeliveryService $deliveryService)
    {
        $request->validate([
            'name'  => 'required|string|max:100',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp,svg|max:2048',
        ]);

        $data = [
            'name'      => $request->name,
            'is_active' => $request->boolean('is_active', false),
        ];

        if ($request->hasFile('image')) {
            if ($deliveryService->image) {
                Storage::disk('public')->delete($deliveryService->image);
            }
            $data['image'] = $request->file('image')->store('delivery-services', 'public');
        }

        $deliveryService->update($data);

        return redirect()->route('admin.delivery-services.index')
            ->with('success', 'Delivery service updated.');
    }

    public function destroy(DeliveryService $deliveryService)
    {
        if ($deliveryService->image) {
            Storage::disk('public')->delete($deliveryService->image);
        }
        $deliveryService->delete();
        return redirect()->route('admin.delivery-services.index')
            ->with('success', 'Delivery service deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        if ($request->boolean('delete_all')) {
            $services = DeliveryService::all();
            foreach ($services as $s) {
                if ($s->image) Storage::disk('public')->delete($s->image);
            }
            $count = $services->count();
            DeliveryService::query()->delete();
        } else {
            $ids = array_filter((array) $request->input('service_ids', []), 'is_numeric');
            if (empty($ids)) {
                return redirect()->route('admin.delivery-services.index')->with('error', 'No services selected.');
            }
            $services = DeliveryService::whereIn('id', $ids)->get();
            foreach ($services as $s) {
                if ($s->image) Storage::disk('public')->delete($s->image);
            }
            $count = $services->count();
            DeliveryService::whereIn('id', $ids)->delete();
        }

        return redirect()->route('admin.delivery-services.index')
            ->with('success', $count . ' service' . ($count === 1 ? '' : 's') . ' deleted.');
    }

    public function toggleStatus(DeliveryService $deliveryService)
    {
        $deliveryService->update(['is_active' => !$deliveryService->is_active]);
        return back()->with('success',
            'Delivery service ' . ($deliveryService->is_active ? 'deactivated' : 'activated') . '.'
        );
    }
}
