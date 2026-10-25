<?php

namespace App\Providers;

use App\Http\Middleware\IsolatedXsrfCookie;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;

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
        // Sanctum's stateful API pipeline uses its own CSRF middleware setting,
        // separate from Laravel's web group. Both must issue the same cookie.
        config()->set('sanctum.middleware.validate_csrf_token', IsolatedXsrfCookie::class);

        ResetPassword::toMailUsing(static function ($recipient, string $token): MailMessage {
            $base = rtrim((string) config('hrm.frontend_url'), '/');
            if (!str_starts_with($base, 'https://') || !str_ends_with($base, '/hrm')) {
                throw new \RuntimeException('HRM password reset frontend URL must be the HTTPS HRM site.');
            }
            $url = $base.'/reset-password?token='.rawurlencode($token)
                .'&email='.rawurlencode($recipient->getEmailForPasswordReset());

            $locale = config('hrm.workflow_notifications_enabled', false)
                ? app(\App\Domain\Notifications\WorkflowOutbox::class)->preferences((int) $recipient->id)['locale'] : 'ru';
            $copy = \App\Domain\Notifications\ResetMailCopy::for($locale);
            return (new MailMessage())->subject($copy[0])->greeting($copy[1])
                ->line($copy[2])->action($copy[3], $url)->line($copy[4])->line($copy[5]);
        });
    }
}
