<?php

return [
    // Currency label only (no formatting)
    'currency' => 'XAF', // FCFA

    // Vehicle types with base rules (from doc)
    // Hiace: 425 / km, +40% motivation
    // Coaster: 725 / km, +25% motivation  :contentReference[oaicite:0]{index=0}

    'vehicles' => [
        'hiace' => [
            'per_km' => 425,
            'motivation_percent' => 0.40,
            'label' => 'Hiace',
        ],
        'coaster' => [
            'per_km' => 725,
            'motivation_percent' => 0.25,
            'label' => 'Coaster',
        ],
    ],

    'min_distance_km' => 5.0,

    /**
     * Event multipliers:
     * - Tier A (0.30): high-demand / high-service complexity
     * - Tier B (0.25): formal/official or solemn events
     * - Tier C (0.20): community/religious/education (keeps your legacy 'church' at 0.20)
     * - Tier D (0.10): simple private transport
     * - none / simple_rental (0.00): baseline
     */
    'events' => [
        // Baseline
        'none'            => 0.00,
        'simple_rental'   => 0.00,

        // Tier D (0.10)
        'private_transport' => 0.10,

        // Tier C (0.20)
        'church'            => 0.20,
        // Added 2026-08-21: the console's event list has offered `pilgrimage`
        // since it was written, but this map did not — so quoting any
        // reservation created with it threw "Unknown event type" from the
        // engine. Priced with the other religious/community trips.
        'pilgrimage'        => 0.20,
        'school_trip'       => 0.20,
        'university_trip'   => 0.20,
        'educational_tour'  => 0.20,
        'student_transport' => 0.20,
        'school_competition'=> 0.20,
        'site_visit'        => 0.20,

        // Tier B (0.25)
        'funeral'              => 0.25,
        'conference'           => 0.25,
        'seminar'              => 0.25,
        'company_trip'         => 0.25,
        'business_mission'     => 0.25,
        'staff_shuttle'        => 0.25,
        'sports_tournament'    => 0.25,
        'tourist_trip'         => 0.25,
        'group_excursion'      => 0.25,
        'airport_transfer'     => 0.25,
        'administrative_mission'=> 0.25,
        'official_trip'        => 0.25,
        'election_campaign'    => 0.25,
        'special_event'        => 0.25,

        // Tier A (0.30)
        'wedding'       => 0.30,
        'birthday'      => 0.30,
        'baptism'       => 0.30,
        'family_meeting'=> 0.30,
        'football_match'=> 0.30,
        'concert'       => 0.30,
        'festival'      => 0.30,
    ],

    // Client Mobile Money fees (+4% on the client price)  :contentReference[oaicite:7]{index=7}
    'mobile_money_client_percent' => 0.04,

    // Commission Móva Mobility (13% deducted from the rounded client amount)  :contentReference[oaicite:8]{index=8}
    'commission_percent' => 0.13,

    // Mobile Money applied to bus payout (+3.5%)  :contentReference[oaicite:9]{index=9}
    'mobile_money_bus_percent' => 0.035,

    // Special upward rounding to local payable amounts (applies to client and bus)
    // Rules from your doc:
    // 1–24 => 25, 26–49 => 50, 51–74 => 75, >75 => next hundred  :contentReference[oaicite:10]{index=10}
    'rounding' => [
        'mode' => 'step25_up', // custom strategy
    ],
];
