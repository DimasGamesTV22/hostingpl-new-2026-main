<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Тонкая обёртка над Laravel Mail: уважает настройки SMTP из панели
 * (они важнее .env), умеет писать в лог, если SMTP не настроен.
 */
class Mailer
{
    private string $to = '';

    private string $subject = '';

    private ?string $body = null;

    public function to(string $email): self
    {
        $this->to = $email;

        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function body(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function send(string $subject = '', string $body = ''): bool
    {
        $subject = $subject ?: $this->subject;
        $body = $body ?: (string) $this->body;

        if (blank($subject) || blank($this->to)) {
            return false;
        }

        $mailer = $this->resolveMailer();

        if ($mailer === 'log') {
            Log::info('[mail] '.$subject.' → '.$this->to, [
                'body' => mb_substr($body, 0, 500),
            ]);

            return true;
        }

        try {
            Mail::html($body, function ($message) use ($subject) {
                $message->to($this->to)->subject($subject);
            })->send();

            return true;
        } catch (\Throwable $e) {
            Log::error('Не удалось отправить письмо: '.$e->getMessage(), [
                'to' => $this->to,
                'subject' => $subject,
            ]);

            return false;
        }
    }

    /** SMTP-реквизиты из админки имеют приоритет над .env. */
    public function resolveMailer(): string
    {
        $host = Setting::firstWhere('key', 'mail.host')?->value;

        if (blank($host)) {
            return (string) config('mail.default', 'smtp');
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => Setting::firstWhere('key', 'mail.port')?->value ?: 587,
            'mail.mailers.smtp.username' => Setting::firstWhere('key', 'mail.username')?->value,
            'mail.mailers.smtp.password' => Setting::firstWhere('key', 'mail.password')?->value,
            'mail.from.address' => Setting::firstWhere('key', 'mail.from_address')?->value ?: config('mail.from.address'),
        ]);

        return 'smtp';
    }
}
