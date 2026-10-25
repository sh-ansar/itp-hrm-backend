<?php

namespace App\Domain\Notifications;

final class ResetMailCopy
{
    public static function for(string $locale): array
    {
        return match ($locale) {
            'kk' => ['ITP HRM — құпиясөзді орнату немесе өзгерту', 'Сәлеметсіз бе!', 'ITP HRM құпиясөзін өзгертуге сұрау түсті.', 'Жаңа құпиясөз жасау', 'Сілтеме 60 минут бойы жарамды және бір рет қолданылады.', 'Сұрауды сіз жібермесеңіз, бұл хатты елемеңіз.'],
            'en' => ['ITP HRM — set or change your password', 'Hello!', 'A password setup or reset was requested for your ITP HRM account.', 'Create a new password', 'This link expires in 60 minutes and can only be used once.', 'If you did not request this, ignore this email.'],
            'ka' => ['ITP HRM — პაროლის დაყენება ან შეცვლა', 'გამარჯობა!', 'მოთხოვნილია თქვენი ITP HRM ანგარიშის პაროლის დაყენება ან შეცვლა.', 'ახალი პაროლის შექმნა', 'ბმული მოქმედებს 60 წუთის განმავლობაში და გამოიყენება მხოლოდ ერთხელ.', 'თუ მოთხოვნა თქვენ არ გაგიგზავნიათ, უგულებელყავით ეს წერილი.'],
            default => ['ITP HRM — установка или смена пароля', 'Здравствуйте!', 'Получен запрос на установку или смену пароля вашей учётной записи ITP HRM.', 'Создать новый пароль', 'Ссылка действительна 60 минут и может быть использована только один раз.', 'Если вы не отправляли запрос, просто проигнорируйте это письмо.'],
        };
    }
}
