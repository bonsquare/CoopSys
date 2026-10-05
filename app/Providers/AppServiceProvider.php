<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Paginator::useTailwind();

        // @peso($amount) prints e.g. ₱1,250.00
        // @allowed('members', 'manage') ... @endallowed  (shows content only if the user's role is allowed)
        Blade::if('allowed', fn (string $module, string $level = 'view') => auth()->check() && auth()->user()->canAccess($module, $level));

        // @acct($amount) prints accounting style: 1,250.00 and (1,250.00) for negatives
        Blade::directive('acct', fn ($expression) => "<?php \$__v = (float) ({$expression}); echo \$__v < -0.004 ? '(₱' . number_format(abs(\$__v), 2) . ')' : '₱' . number_format(\$__v, 2); ?>");

        Blade::directive('peso', fn ($expression) => "<?php echo '₱' . number_format((float) ({$expression}), 2); ?>");
    }
}
