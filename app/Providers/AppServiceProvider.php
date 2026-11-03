<?php

namespace App\Providers;

use App\Models\User;
use App\Notifications\TemplatedNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Outgoing email: use the SMTP server configured in Admin → Email servers.
        $this->app->afterResolving('mail.manager', fn () => \App\Support\Mail\MailServerConfig::apply());

        // Sub-folder hosting (www.waveteam.lk/app): see SubfolderAwareHandleRequests.
        $this->app->instance(
            \Livewire\Mechanisms\HandleRequests\HandleRequests::class,
            new \App\Support\Livewire\SubfolderAwareHandleRequests,
        );
        $this->app->booted(function () {
            $base = \App\Support\Livewire\SubfolderAwareHandleRequests::basePath();
            $route = app(\Livewire\Mechanisms\FrontendAssets\FrontendAssets::class)->javaScriptRoute ?? null;
            if ($base !== '' && $route && ! config('livewire.asset_url')) {
                config(['livewire.asset_url' => $base.'/'.ltrim($route->uri, '/')]);
            }
        });

        // SMS / WhatsApp provider. Add real adapters here (e.g. 'dialog' => DialogGateway::class).
        $this->app->bind(\App\Support\Messaging\TextMessageGateway::class, fn () => match (config('portal.channels.driver')) {
            default => new \App\Support\Messaging\LogGateway,
        });
    }

    public function boot(): void
    {
        // Catch N+1 queries, silently discarded attributes and missing
        // attributes during development; relaxed in production.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Force https links whenever the site is served over https (production);
        // a plain-http local deployment keeps working.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Applies to every password set anywhere in the system.
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(10)->letters()->mixedCase()->numbers()->uncompromised()
            : Password::min(10)->letters()->numbers());

        // Named rate limiters. Plain "throttle:N,1" shares ONE counter per user
        // across every route, so each action gets its own bucket here.
        $perUser = fn (string $name, int $perMinute) => RateLimiter::for($name, fn (Request $request) => Limit::perMinute($perMinute)
            ->by($name.'|'.($request->user()?->id ?: $request->ip())));
        $perUser('profile-writes', 60);
        $perUser('uploads', 15);
        $perUser('pcc-uploads', 5);
        $perUser('applications', 10);
        $perUser('documents', 120);
        $perUser('registration', 10);      // guests: per IP
        $perUser('password-email', 5);
        $perUser('password-reset', 10);
        $perUser('verification', 6);
        $perUser('account-security', 6);
        $perUser('service-requests', 10);
        $perUser('messages', 20);

        // Verification email uses the admin-editable template.
        VerifyEmail::toMailUsing(fn (User $user, string $url) => (new TemplatedNotification('account.verify_email', actionUrl: $url, actionText: __('Verify email address')))
            ->toMail($user));

        // Portal users reset via the portal page; staff via the admin panel's own flow.
        ResetPassword::toMailUsing(fn (User $user, string $token) => (new MailMessage)
            ->subject(__('Reset your WaveTeam password'))
            ->greeting(__('Hello :name,', ['name' => $user->name]))
            ->line(__('We received a request to reset the password for your WaveTeam account.'))
            ->action(__('Reset password'), route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->line(__('This link expires in :count minutes. If you did not request a reset, you can ignore this email — your password will not change.', ['count' => config('auth.passwords.users.expire')]))
            ->salutation(__('Regards,')."\n".config('portal.company.name')));

        // Super Admin passes every permission check (but policies may still
        // return explicit denials via Response::deny for hard business rules).
        Gate::before(function (User $user, string $ability) {
            return $user->isStaff() && $user->hasRole(RolesAndPermissionsSeeder::SUPER_ADMIN) ? true : null;
        });
    }
}
