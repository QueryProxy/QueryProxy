<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The mail channel for lifecycle notifications. When the queue runs inline
 * (QUEUE_CONNECTION=sync) a transport failure is logged and swallowed: like chat,
 * an e-mail problem never blocks a request. On a real queue the job is separate
 * from the request, so the failure is logged and thrown again: the job lands in
 * failed_jobs and `queue:retry` can resend it. Password-reset mail does not use
 * this channel; there a failure must stay visible.
 */
class SafeMailChannel
{
    public function __construct(private MailChannel $mail) {}

    public function send(object $notifiable, Notification $notification): void
    {
        try {
            $this->mail->send($notifiable, $notification);
        } catch (Throwable $e) {
            Log::warning('QueryProxy: e-mail notification failed', [
                'notification' => $notification::class,
                'user_id' => $notifiable->getKey(),
                'error' => $e::class.': '.$e->getMessage(),
            ]);

            if (! $this->runsInline($notification)) {
                throw $e;
            }
        }
    }

    private function runsInline(Notification $notification): bool
    {
        $connection = $notification->connection ?? config('queue.default');

        return config("queue.connections.{$connection}.driver") === 'sync';
    }
}
