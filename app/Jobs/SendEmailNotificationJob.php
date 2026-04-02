<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendEmailNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param array<int, string> $emails
     */
    public function __construct(
        private array $emails,
        private string $subject,
        private string $body,
        private bool $isHtml = false,
        private string $context = 'notification'
    ) {
    }

    public function handle(): void
    {
        foreach ($this->emails as $email) {
            if (!is_string($email) || trim($email) === '') {
                continue;
            }

            try {
                if ($this->isHtml) {
                    Mail::html($this->body, function ($message) use ($email) {
                        $message->to($email)->subject($this->subject);
                    });
                    continue;
                }

                Mail::raw($this->body, function ($message) use ($email) {
                    $message->to($email)->subject($this->subject);
                });
            } catch (\Throwable $e) {
                Log::warning('Failed to send queued email notification', [
                    'context' => $this->context,
                    'email' => $email,
                    'subject' => $this->subject,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
