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

    /**
     * Turning a car journey time into a bus journey time.
     *
     * Google Directions has no vehicle profile — `mode=driving` is a car, and
     * `mode=transit` routes against published timetables Mova is not in. So the
     * geometry comes from driving and the clock is adjusted here.
     *
     * Both values are ESTIMATES. Calibrate them against real trip logs rather
     * than treating them as settled: they live in config precisely so that is
     * a one-line change. See App\Domain\Booking\BusTravelTime.
     */
    'bus_travel' => [
        /*
         * Bus running speed is generally 75–85% of car speed on the same urban
         * corridor — heavier vehicle, slower acceleration and cornering, lower
         * cruising speed. 1.25 sits at the conservative end: quoting a journey
         * slightly long is recoverable, quoting it short strands a wedding
         * party.
         */
        'duration_factor' => 1.25,

        /*
         * Per intermediate pickup. Does NOT scale with distance — boarding a
         * group costs the same whether the next leg is 2 km or 20.
         */
        'stop_dwell_minutes' => 5,
    ],
];
