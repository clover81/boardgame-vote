<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PollVoteReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $pollId,
        public readonly string $pollName,
        public readonly string $playerName,
        public readonly int $completed,
        public readonly int $total,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ready = $this->completed === $this->total;
        $mail = (new MailMessage)
            ->subject($ready
                ? "Ya puedes cerrar: {$this->pollName}"
                : "{$this->playerName} ha votado: {$this->pollName}")
            ->greeting('¡Hola!')
            ->line("{$this->playerName} ha votado en «{$this->pollName}». Van {$this->completed} de {$this->total}.");

        if ($ready) {
            $mail->line('Ya han votado todos. Puedes cerrar la votación.');
        }

        return $mail->action('Abrir votación', route('polls.show', $this->pollId));
    }
}
