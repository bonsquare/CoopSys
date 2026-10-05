<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Tenant;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Tenant::class);
    }

    public function boot(): void
    {
        // Whenever a user is signed in (or loaded from the session), work inside their organization
        Event::listen([Login::class, Authenticated::class], fn ($event) => Tenant::set($event->user->organization_id));
        Event::listen(Logout::class, fn () => Tenant::forget());

        // {user} in a URL may only be someone from the same organization
        Route::bind('user', fn (string $id) => User::inOrganization()->findOrFail($id));

        Paginator::useTailwind();

        // @peso($amount) prints e.g. ₱1,250.00
        // @allowed('members', 'manage') ... @endallowed  (shows content only if the user's role is allowed)
        Blade::if('allowed', fn (string $module, string $level = 'view') => auth()->check() && auth()->user()->canAccess($module, $level));

        // @acct($amount) prints accounting style: 1,250.00 and (1,250.00) for negatives
        Blade::directive('acct', fn ($expression) => "<?php \$__v = (float) ({$expression}); echo \$__v < -0.004 ? '(₱' . number_format(abs(\$__v), 2) . ')' : '₱' . number_format(\$__v, 2); ?>");

        Blade::directive('peso', fn ($expression) => "<?php echo '₱' . number_format((float) ({$expression}), 2); ?>");
    }
}
