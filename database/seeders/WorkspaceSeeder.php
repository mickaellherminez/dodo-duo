<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;

class WorkspaceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create default users for workspaces
        $users = User::factory()->count(3)->create();

        // Create 3 default workspaces with different statuses
        Workspace::create([
            'name' => 'Acme Corporation',
            'slug' => 'acme-corp',
            'domain' => 'acme.saasforge.local',
            'status' => 'active',
            'owner_id' => $users[0]->id,
            'settings' => [
                'theme' => 'light',
                'timezone' => 'UTC',
            ],
            'trial_ends_at' => now()->addDays(30),
        ]);

        Workspace::create([
            'name' => 'Tech Startup Inc',
            'slug' => 'tech-startup',
            'domain' => null,
            'status' => 'active',
            'owner_id' => $users[1]->id,
            'settings' => [
                'theme' => 'dark',
                'timezone' => 'America/New_York',
            ],
            'trial_ends_at' => null,
        ]);

        Workspace::create([
            'name' => 'Global Solutions',
            'slug' => 'global-solutions',
            'domain' => 'global.saasforge.local',
            'status' => 'active',
            'owner_id' => $users[2]->id,
            'settings' => [
                'theme' => 'light',
                'timezone' => 'Europe/Paris',
            ],
            'trial_ends_at' => now()->addDays(14),
        ]);
    }
}
