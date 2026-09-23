<?php

namespace App\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as ViewInstance;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (['money', 'rate', 'percentage'] as $format) {
            Blade::directive($format, fn (string $expression): string => "<?php echo e(\\App\\Services\\DecimalFormatter::{$format}({$expression})); ?>");
        }
        View::composer('components.notification-menu', function (ViewInstance $view): void {
            $user = auth()->user();
            $query = $user->notifications()->visibleTo($user);
            $view->with('unreadCount', (clone $query)->unread()->count())
                ->with('records', $query->limit(5)->get());
        });
    }
}
