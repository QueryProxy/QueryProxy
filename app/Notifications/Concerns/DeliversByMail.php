<?php

namespace App\Notifications\Concerns;

use App\Enums\ChatSqlMode;
use App\Enums\MailEvent;
use App\Models\QueryRequest;
use App\Models\User;
use App\Notifications\Channels\SafeMailChannel;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Adds an optional e-mail channel to a lifecycle notification (PRD 3.4).
 *
 * - The user chooses per event (users.notification_preferences); all off by default.
 * - The mail is queued, so the web request never waits for the mail server. The in-app
 *   copy is not: viaConnections() writes it at once, as before, whatever the worker is doing.
 * - Whether the recipient may still get mail is decided when the mail is sent, not when it
 *   was queued (shouldSend): a user suspended or deleted in between gets nothing.
 * - The message carries no result data and no free text a user typed (rejection
 *   reasons, notes, error messages). The only SQL it can hold is the
 *   literal-redacted preview, and none when the chat SQL mode is `none` (ADR-017).
 * - Every link leads to a page that requires a signed-in session; nothing is
 *   decided from an e-mail.
 *
 * The class using this trait implements ShouldQueue and defines mailEvent() and toMail().
 */
trait DeliversByMail
{
    /** A request deleted before the job runs is no reason to fail it. */
    public bool $deleteWhenMissingModels = true;

    abstract protected function mailEvent(): MailEvent;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable instanceof User && $notifiable->wantsMail($this->mailEvent())) {
            $channels[] = SafeMailChannel::class;
        }

        return $channels;
    }

    /** The in-app copy is written inline; only the other channels go to the queue. */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    /**
     * Runs when the channel is about to send, so after any wait in the queue. Mail goes only
     * to an active user who still has the event turned on; the in-app copy is not affected.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        if ($channel !== SafeMailChannel::class) {
            return true;
        }

        return $notifiable instanceof User
            && ! $notifiable->trashed()
            && ! $notifiable->isSuspended()
            && $notifiable->wantsMail($this->mailEvent());
    }

    /** Subject and greeting; the caller adds the body, the link and mailClosing(). */
    protected function mailMessage(string $subject): MailMessage
    {
        return (new MailMessage)
            ->subject('['.config('app.name').'] '.$subject)
            ->greeting('Hello,');
    }

    /** The shared closing lines; call it after ->action(), so they follow the button. */
    protected function mailClosing(MailMessage $message): MailMessage
    {
        return $message
            ->line('The link asks you to sign in first. Nothing is approved, rejected or changed from this e-mail.')
            ->line('You get this e-mail because you turned it on in your profile. You can turn it off there.')
            ->salutation(config('app.name'));
    }

    /**
     * A name typed by a user, safe to put in a Markdown line: it cannot start a
     * link, emphasis or a code span, and it stays on one line. HTML characters
     * need no handling here; the mail template escapes every line.
     */
    protected function mailText(?string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));

        return (string) preg_replace('/([\\\\`*_{}\[\]()#+!|~])/', '\\\\$1', $text);
    }

    /**
     * The statement with its literals replaced by `?`, cut after redaction, or null when
     * the SQL must not be sent: SQL mode `none`, or a statement the redactor cannot parse
     * with confidence. The `full` chat mode does not apply to e-mail; raw SQL never goes
     * out by e-mail. Show it through mailText(), like any other text from a user.
     */
    protected function redactedSql(QueryRequest $request): ?string
    {
        if (ChatSqlMode::fromConfig(config('queryproxy.chat_include_sql')) === ChatSqlMode::None) {
            return null;
        }

        $sql = $request->redactedSql();

        if ($sql === null || trim($sql) === '') {
            return null;
        }

        return (string) str($sql)->limit(400);
    }
}
