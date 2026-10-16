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

            return (new MailMessage())
                ->subject('ITP HRM — установка или смена пароля')
                ->greeting('Здравствуйте!')
                ->line('Получен запрос на установку или смену пароля вашей учётной записи ITP HRM.')
                ->action('Создать новый пароль', $url)
                ->line('Ссылка действительна 60 минут и может быть использована только один раз.')
                ->line('Если вы не отправляли запрос, просто проигнорируйте это письмо.');
        });
    }
}
