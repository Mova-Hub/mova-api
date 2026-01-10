<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class OrderHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // $this refers to the Order model
        $reservation = $this->reservation; // HasOne relationship

        // Determine logic based on status
        $isConfirmed = $this->status === 'converted' && $reservation;
        $price = $isConfirmed ? $reservation->price_total : null;

        // Format Date nicely (e.g. "14 Oct 2023")
        $dateObj = Carbon::parse($this->pickup_date);
        $dateFormatted = $dateObj->translatedFormat('d M Y');

        // Vehicle Info (Grab first bus or generic info)
        $vehicleInfo = 'Standard';
        $plateInfo = '---';

        if ($isConfirmed && $reservation->buses->isNotEmpty()) {
            $bus = $reservation->buses->first();
            $vehicleInfo = $bus->name ?? 'Bus Assigné';
            $plateInfo = $bus->plate_number ?? '---';
        }

        return [
            'id' => 'CMD-' . $this->id, // Friendly ID
            'raw_id' => $this->id,
            'status' => $this->status, // pending, contacted, converted, cancelled
            'eventName' => $this->event_type === 'wedding' ? 'Mariage' : ucfirst($this->event_type),
            'eventType' => $this->event_type,
            'date' => $dateFormatted,
            'raw_date' => $this->pickup_date, // for sorting logic in app
            'route' => "{$this->origin} ➔ {$this->destination}",
            'fromTime' => $this->pickup_time,
            'toTime' => null, // We generally don't have this in OrderRequest yet
            'pax' => array_sum($this->fleet_requirements), // Estimate pax based on buses? Or just sum quantities
            'price' => $price,
            'driver' => 'Chauffeur Assigné', // Placeholder until you add Driver model
            'vehicle' => $vehicleInfo,
            'plate' => $plateInfo,
            // Random image based on type for UI flair
            'image' => $this->getImageForType($this->event_type),
        ];
    }

    private function getImageForType($type) {
        $images = [
            'wedding' => 'https://images.unsplash.com/photo-1519741497674-611481863552?q=80&w=400&auto=format&fit=crop',
            'school' => 'https://images.unsplash.com/photo-1509062522246-3755977927d7?q=80&w=400&auto=format&fit=crop',
            'business' => 'https://images.unsplash.com/photo-1515187029135-18ee286d815b?q=80&w=400&auto=format&fit=crop',
        ];
        return $images[$type] ?? 'https://images.unsplash.com/photo-1570125909232-eb263c188f7e?q=80&w=400&auto=format&fit=crop';
    }
}
