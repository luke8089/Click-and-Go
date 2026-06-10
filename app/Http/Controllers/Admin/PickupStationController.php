<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PickupStation;
use Illuminate\Http\Request;

class PickupStationController extends Controller
{
    public function index()
    {
        $stations = PickupStation::orderBy('name')->get();
        return view('admin.pickup-stations.index', compact('stations'));
    }

    public function create()
    {
        return view('admin.pickup-stations.form');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'      => 'required|string|max:100',
            'location'  => 'nullable|string|max:200',
            'price'     => 'required|numeric|min:0',
        ]);

        PickupStation::create([
            'name'      => $request->name,
            'location'  => $request->location,
            'price'     => $request->price,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('admin.pickup-stations.index')
            ->with('success', 'Pickup station created successfully.');
    }

    public function edit(PickupStation $pickupStation)
    {
        $station = $pickupStation;
        return view('admin.pickup-stations.form', compact('station'));
    }

    public function update(Request $request, PickupStation $pickupStation)
    {
        $request->validate([
            'name'      => 'required|string|max:100',
            'location'  => 'nullable|string|max:200',
            'price'     => 'required|numeric|min:0',
        ]);

        $pickupStation->update([
            'name'      => $request->name,
            'location'  => $request->location,
            'price'     => $request->price,
            'is_active' => $request->boolean('is_active', false),
        ]);

        return redirect()->route('admin.pickup-stations.index')
            ->with('success', 'Pickup station updated successfully.');
    }

    public function destroy(PickupStation $pickupStation)
    {
        $pickupStation->delete();

        return redirect()->route('admin.pickup-stations.index')
            ->with('success', 'Pickup station deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        if ($request->boolean('delete_all')) {
            $count = PickupStation::count();
            PickupStation::query()->delete();
        } else {
            $ids = array_filter((array) $request->input('station_ids', []), 'is_numeric');
            if (empty($ids)) {
                return redirect()->route('admin.pickup-stations.index')->with('error', 'No stations selected.');
            }
            $count = PickupStation::whereIn('id', $ids)->delete();
        }

        return redirect()->route('admin.pickup-stations.index')
            ->with('success', $count . ' station' . ($count === 1 ? '' : 's') . ' deleted.');
    }

    public function toggleStatus(PickupStation $pickupStation)
    {
        $pickupStation->update(['is_active' => !$pickupStation->is_active]);

        return back()->with('success',
            'Pickup station ' . ($pickupStation->is_active ? 'deactivated' : 'activated') . '.'
        );
    }
}
