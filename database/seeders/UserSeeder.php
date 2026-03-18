<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [
            // Admins
            [
                'role' => 'admin',
                'name' => 'Van Christ',
                'phone' => '+242067000111',
                'email' => 'van.bouetoumoussa@mova-mobility.com',
            ],
            [
                'role' => 'admin',
                'name' => 'Switch Aime',
                'phone' => '+242067000222',
                'email' => 'swith.aime@mova-mobility.com',
            ],
            [
                'role' => 'admin',
                'name' => 'Arden BOUET',
                'phone' => '+242064074926',
                'email' => 'laudbouetoumoussa@mova-mobility.com',
            ],

            // Agents
            [
                'role' => 'agent',
                'name' => 'Mireille Okomo',
                'phone' => '+242067000333',
                'email' => 'mireille.okomo@mova-mobility.com',
            ],
            [
                'role' => 'agent',
                'name' => 'Gildas Makita',
                'phone' => '+242067000444',
                'email' => 'gildas.makita@mova-mobility.com',
            ],
            [
                'role' => 'agent',
                'name' => 'Prisca Mavouadi',
                'phone' => '+242067000555',
                'email' => 'prisca.mavouadi@mova-mobility.com',
            ],
            [
                'role' => 'agent',
                'name' => 'Dieudonné Ikama',
                'phone' => '+242067000666',
                'email' => 'dieudonne.ikama@mova-mobility.com',
            ],
            [
                'role' => 'agent',
                'name' => 'Romaric Kodia',
                'phone' => '+242067000777',
                'email' => 'romaric.kodia@mova-mobility.com',
            ],
            [
                'role' => 'agent',
                'name' => 'Nadia Ndinga',
                'phone' => '+242067000888',
                'email' => 'nadia.ndinga@mova-mobility.com',
            ],
        ];

        foreach ($users as $data) {
            User::updateOrCreate(
                ['email' => $data['email']],
                array_merge($data, [
                    'password' => Hash::make('Password123!'),
                ])
            );
        }

        $this->command->info('✅ Users seeded successfully!');
    }
}
