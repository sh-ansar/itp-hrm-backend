<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

final class WorkflowUpdateMail extends Mailable
{
    public function __construct(public string $notificationId, public string $correlationId, public string $language) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->language) {
            'kk' => 'ITP HRM — кадрлық процесс жаңартылды',
            'en' => 'ITP HRM — personnel workflow update',
            'ka' => 'ITP HRM — საკადრო პროცესის განახლება',
            default => 'ITP HRM — обновление кадрового процесса',
        });
    }

    public function headers(): Headers
    {
        return new Headers(messageId: $this->notificationId.'@hrm.itpmining.kz',
            text: ['X-HRM-Correlation-ID' => $this->correlationId]);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.workflow-update', with: [
            'messageText' => match ($this->language) {
                'kk' => 'Кадрлық процесс жаңартылды. Мәліметтерді рұқсат етілген HRM тіркелгіңізден қараңыз.',
                'en' => 'A personnel workflow has been updated. Sign in to your authorized HRM account to review it.',
                'ka' => 'საკადრო პროცესი განახლდა. დეტალებისთვის შედით თქვენს უფლებამოსილ HRM ანგარიშში.',
                default => 'Кадровый процесс обновлён. Откройте свою учётную запись HRM для просмотра.',
            },
            'url' => rtrim((string) config('hrm.frontend_url'), '/'),
        ]);
    }
}
