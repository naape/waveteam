<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Enums\UserType;
use App\Models\User;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\PolicySeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * One-command installer / upgrader for a server:
 *   php artisan portal:install
 *
 * Safe to re-run: keys are only generated when missing, seeders never
 * overwrite admin edits, and the first Super Admin is created only once.
 */
class InstallPortal extends Command
{
    protected $signature = 'portal:install
        {--admin-email= : Email for the first Super Admin (default SEED_ADMIN_EMAIL)}
        {--demo : Also load demo vacancies and a demo client (NEVER on production)}
        {--force : Run without confirmation in production}';

    protected $description = 'Install or upgrade the WaveTeam Portal on this server.';

    public function handle(): int
    {
        $this->components->info('WaveTeam Portal installer');

        if (app()->isProduction() && ! $this->option('force') && ! $this->confirm('This is PRODUCTION. Continue?', true)) {
            return self::FAILURE;
        }

        if (! $this->checkRequirements()) {
            return self::FAILURE;
        }

        if (! $this->ensureKeys()) {
            return self::FAILURE;
        }

        $this->components->task('Checking database connection', function () {
            DB::connection()->getPdo();
        });

        $this->components->task('Running migrations', fn () => $this->callSilently('migrate', ['--force' => true]) === 0);

        $this->components->task('Loading reference data, roles, email templates, policies', function () {
            foreach ([ReferenceDataSeeder::class, RolesAndPermissionsSeeder::class, NotificationTemplateSeeder::class, PolicySeeder::class] as $seeder) {
                $this->callSilently('db:seed', ['--class' => $seeder, '--force' => true]);
            }
        });

        $this->createSuperAdmin();

        if ($this->option('demo')) {
            if (app()->isProduction()) {
                $this->components->warn('Skipping demo data on production.');
            } else {
                $this->callSilently('db:seed', ['--class' => 'DemoVacancySeeder']);
                $this->callSilently('db:seed', ['--class' => 'DemoOrganizationSeeder']);
                $this->components->info('Demo data loaded (client login: kumari@ltc.lk / SecurePass123).');
            }
        }

        $this->components->task('Preparing private document storage', function () {
            $root = config('filesystems.disks.documents.root');
            File::ensureDirectoryExists($root, 0700);

            return is_writable($root);
        });

        if (app()->isProduction()) {
            $this->call('portal:optimize');
        }

        $this->newLine();
        $this->components->info('Done.');
        $this->line('  Next steps:');
        $this->line('  1. Add the cron entry:  * * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1');
        $this->line('  2. BACK UP the keys in .env (APP_KEY, BLIND_INDEX_KEY, DOCUMENT_ENCRYPTION_KEY) somewhere safe and separate.');
        $this->line('  3. Sign in at '.url(config('portal.admin_path')).' and set up the authenticator app.');

        return self::SUCCESS;
    }

    private function checkRequirements(): bool
    {
        $ok = true;
        $checks = [
            'PHP >= 8.3' => version_compare(PHP_VERSION, '8.3.0', '>='),
            'ext-sodium (document encryption)' => extension_loaded('sodium'),
            'ext-pdo_mysql' => extension_loaded('pdo_mysql'),
            'ext-mbstring' => extension_loaded('mbstring'),
            'ext-intl' => extension_loaded('intl'),
            'ext-gd (images, PDFs)' => extension_loaded('gd'),
            'ext-zip (Excel exports)' => extension_loaded('zip'),
            'ext-fileinfo (upload checks)' => extension_loaded('fileinfo'),
            'storage/ writable' => is_writable(storage_path()),
            'bootstrap/cache writable' => is_writable(base_path('bootstrap/cache')),
            'Built assets present (public/build)' => File::exists(public_path('build/manifest.json')),
        ];

        foreach ($checks as $label => $pass) {
            $this->components->twoColumnDetail($label, $pass ? '<fg=green>OK</>' : '<fg=red>MISSING</>');
            $ok = $ok && $pass;
        }

        if (app()->isProduction() && config('app.debug')) {
            $this->components->error('APP_DEBUG must be false in production.');
            $ok = false;
        }

        return $ok;
    }

    /** Generates any missing key in .env (never replaces an existing one). */
    private function ensureKeys(): bool
    {
        $envPath = base_path('.env');
        if (! File::exists($envPath)) {
            $this->components->error('.env not found. Copy deploy/env.production.example to .env and fill it in first.');

            return false;
        }

        $env = File::get($envPath);

        if (preg_match_all('/^([A-Z_]+)=.*CHANGE_ME/m', $env, $todo) && $todo[1]) {
            $this->components->error('Fill these values in .env first: '.implode(', ', $todo[1]));

            return false;
        }

        $keys = [
            'APP_KEY' => fn () => 'base64:'.base64_encode(random_bytes(32)),
            'BLIND_INDEX_KEY' => fn () => bin2hex(random_bytes(32)),
            'DOCUMENT_ENCRYPTION_KEY' => fn () => 'base64:'.base64_encode(random_bytes(32)),
        ];

        $generated = [];
        foreach ($keys as $name => $make) {
            if (preg_match("/^{$name}=(.+)$/m", $env)) {
                continue;
            }
            $value = $make();
            $env = preg_match("/^{$name}=.*$/m", $env)
                ? preg_replace("/^{$name}=.*$/m", "{$name}={$value}", $env)
                : rtrim($env)."\n{$name}={$value}\n";
            $generated[] = $name;
        }

        if ($generated) {
            File::put($envPath, $env);
            $this->components->warn('Generated: '.implode(', ', $generated).' — back these up now (see step 2 below).');
            $this->callSilently('config:clear');
            // Reload so the rest of the install uses the new keys.
            foreach ($generated as $name) {
                preg_match("/^{$name}=(.+)$/m", $env, $m);
                $configKey = ['APP_KEY' => 'app.key', 'BLIND_INDEX_KEY' => 'portal.blind_index_key', 'DOCUMENT_ENCRYPTION_KEY' => 'portal.document_key'][$name];
                config([$configKey => $m[1]]);
            }
        } else {
            $this->components->twoColumnDetail('Encryption keys', '<fg=green>present (unchanged)</>');
        }

        return true;
    }

    private function createSuperAdmin(): void
    {
        if (User::staff()->exists()) {
            $this->components->twoColumnDetail('Super Admin', '<fg=green>already exists</>');

            return;
        }

        $email = $this->option('admin-email') ?: env('SEED_ADMIN_EMAIL', 'admin@waveteam.lk');
        $password = Str::password(20);

        $admin = new User(['name' => 'WaveTeam Super Admin', 'email' => mb_strtolower($email), 'password' => $password]);
        $admin->user_type = UserType::Staff;
        $admin->status = AccountStatus::Active;
        $admin->email_verified_at = now();
        $admin->save();
        $admin->assignRole(RolesAndPermissionsSeeder::SUPER_ADMIN);

        $this->newLine();
        $this->components->warn('First Super Admin created — shown ONCE, store it securely:');
        $this->line("    Email:    {$admin->email}");
        $this->line("    Password: {$password}");
        $this->newLine();
    }
}
