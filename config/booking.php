<?php

return [
    /**
     * Seat capacity per vehicle type.
     *
     * Lives here rather than in config/pricing.php because it is a dispatch
     * fact, not a pricing input — the engine never reads it. The order endpoint
     * uses it to reject a request that books fewer seats than passengers.
     *
     * Mirrors VEHICLES in mobile/src/features/booking/constants.ts. The app
     * does the same check to keep the wizard honest; this copy is the one that
     * is authoritative, because the app's numbers arrive over the wire.
     */
    'vehicle_seats' => [
        'hiace'   => 15,
        'coaster' => 30,
    ],
];
