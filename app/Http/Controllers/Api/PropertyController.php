<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PropertyResource as PropertyApiResource;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class PropertyController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'guests' => ['nullable', 'integer', 'min:1'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'check_in' => ['nullable', 'date'],
            'check_out' => ['nullable', 'date', 'after:check_in'],
        ]);

        $query = Property::with([
            'images',
            'reviews' => fn ($reviewQuery) => $reviewQuery
                ->where('status', 'approved')
                ->latest()
                ->with('client:id,name'),
        ])
            ->where('status', 'active');

        if (filled($filters['q'] ?? null)) {
            $search = $filters['q'];

            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('title', 'like', '%'.$search.'%')
                    ->orWhere('address', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        if (filled($filters['guests'] ?? null)) {
            $query->where('max_guests', '>=', (int) $filters['guests']);
        }

        if (filled($filters['max_price'] ?? null)) {
            $query->where('price_per_night', '<=', $filters['max_price']);
        }

        if (filled($filters['check_in'] ?? null) && filled($filters['check_out'] ?? null)) {
            $query->whereDoesntHave('bookings', function (Builder $bookingQuery) use ($filters): void {
                $bookingQuery
                    ->active()
                    ->overlapping($filters['check_in'], $filters['check_out']);
            });
        }

        $properties = $query->paginate(12);

        return PropertyApiResource::collection($properties);
    }

    public function show($slug)
    {
        $property = Property::with([
            'images',
            'reviews' => fn ($reviewQuery) => $reviewQuery
                ->where('status', 'approved')
                ->latest()
                ->with('client:id,name'),
        ])
            ->where('status', 'active')
            ->where('slug', $slug)
            ->firstOrFail();

        return new PropertyApiResource($property);
    }

    public function availability($slug)
    {
        $property = Property::where('slug', $slug)->firstOrFail();

        $bookings = $property->bookings()->where('status', '!=', 'cancelled')->get(['check_in', 'check_out']);

        $blocked = [];
        foreach ($bookings as $b) {
            $blocked[] = ['from' => $b->check_in->toDateString(), 'to' => $b->check_out->toDateString()];
        }

        return response()->json(['blocked' => $blocked]);
    }
}
