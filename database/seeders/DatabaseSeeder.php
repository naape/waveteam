<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ReferenceDataSeeder::class,
            RolesAndPermissionsSeeder::class,
            NotificationTemplateSeeder::class,
            PolicySeeder::class,
        ]);

        $this->createInitialSuperAdmin();
    }

    /**
     * Creates the first Super Admin only if no staff account exists yet.
     * The password is random and printed once; MFA set-up is forced at first login.
     */
    private function createInitialSuperAdmin(): void
    {
        if (User::staff()->exists()) {
            return;
        }

        $email = env('SEED_ADMIN_EMAIL', 'admin@waveteam.lk');
        $password = Str::password(20);

        $admin = new User(['name' => 'WaveTeam Super Admin', 'email' => $email, 'password' => $password]);
        $admin->user_type = UserType::Staff;
        $admin->status = AccountStatus::Active;
        $admin->email_verified_at = now();
        $admin->save();
        $admin->assignRole(RolesAndPermissionsSeeder::SUPER_ADMIN);

        $this->command?->warn('Initial Super Admin created — store these credentials securely, they are shown only once:');
        $this->command?->line("  Email:    {$email}");
        $this->command?->line("  Password: {$password}");
    }
}
