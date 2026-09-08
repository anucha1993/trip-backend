<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function index()
    {
        return response()->json(
            Location::orderBy('name')->get()->map(fn (Location $location) => $this->present($location))
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_meters' => ['nullable', 'integer', 'min:1'],
        ]);

        $data['qr_token'] = Location::generateQrToken();

        $location = Location::create($data);

        return response()->json($this->present($location), 201);
    }

    public function update(Request $request, Location $location)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_meters' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $location->update($data);

        return response()->json($this->present($location));
    }

    public function destroy(Location $location)
    {
        $location->delete();

        return response()->json(['message' => 'Location deleted.']);
    }

    public function regenerateToken(Location $location)
    {
        $location->update(['qr_token' => Location::generateQrToken()]);

        return response()->json($this->present($location));
    }

    public function qrImage(Location $location)
    {
        $result = Builder::create()
            ->writer(new PngWriter())
            ->data($this->scanUrl($location))
            ->size(320)
            ->margin(12)
            ->build();

        return response($result->getString(), 200)
            ->header('Content-Type', $result->getMimeType());
    }

    private function scanUrl(Location $location): string
    {
        return rtrim(config('app.frontend_url'), '/').'/checkin?token='.$location->qr_token;
    }

    private function present(Location $location): array
    {
        return [
            'id' => $location->id,
            'name' => $location->name,
            'address' => $location->address,
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
            'radius_meters' => $location->radius_meters,
            'is_active' => $location->is_active,
            'qr_token' => $location->qr_token,
            'scan_url' => $this->scanUrl($location),
            'qr_image_url' => route('locations.qr', $location),
        ];
    }
}
