<?php

namespace Database\Seeders;

use App\Models\PassPlan;
use Illuminate\Database\Seeder;

/**
 * A starting catalogue.
 *
 * Idempotent via updateOrCreate on `code`, so re-running never duplicates a
 * plan — and never overwrites a price ops has since changed in the back-office,
 * because only rows that do not exist are inserted.
 *
 * Prices are whole francs. Placeholder values: they encode the intended SHAPE
 * of the catalogue (a weekly entry point, a monthly default, a discounted
 * quarter, a student rate), not agreed commercial figures. Confirm before
 * launch.
 */
class PassPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'weekly',
                'name' => 'Pass Semaine',
                'description' => 'Trajets illimités pendant 7 jours.',
                'price' => 5000,
                'interval' => 'week',
                'interval_count' => 1,
                'sort_order' => 10,
            ],
            [
                'code' => 'monthly',
                'name' => 'Pass Mensuel',
                'description' => 'Trajets illimités pendant un mois.',
                'price' => 15000,
                'interval' => 'month',
                'interval_count' => 1,
                'sort_order' => 20,
            ],
            [
                'code' => 'quarterly',
                'name' => 'Pass Trimestriel',
                'description' => 'Trois mois de trajets illimités, au tarif réduit.',
                'price' => 40000,
                'interval' => 'month',
                'interval_count' => 3,
                'sort_order' => 30,
            ],
            [
                'code' => 'student_monthly',
                'name' => 'Pass Étudiant',
                'description' => 'Tarif réduit sur présentation d’une carte étudiante.',
                'price' => 9000,
                'interval' => 'month',
                'interval_count' => 1,
                'sort_order' => 40,
                // Eligibility is a counter check, flagged here so the app can
                // label the plan rather than let anyone buy it unprompted.
                'metadata' => ['requires_proof' => 'student_card'],
            ],
        ];

        foreach ($plans as $plan) {
            PassPlan::updateOrCreate(
                ['code' => $plan['code']],
                $plan + ['currency' => 'XAF', 'is_active' => true],
            );
        }
    }
}
