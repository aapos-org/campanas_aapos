<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Invitation for a user created by an administrator. The link is a signed URL valid
 * for INVITATION_HOURS; opening it issues a fresh password-reset token.
 */
class UserInvitation extends Notification
{
    use Queueable;

    public const int INVITATION_HOURS = 72;

    public function __construct(public readonly string $invitedBy) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            'invitation.accept',
            now()->addHours(self::INVITATION_HOURS),
            ['user' => $notifiable->id, 'hash' => sha1($notifiable->email)],
        );

        return (new MailMessage)
            ->subject('Invitación a '.config('app.name'))
            ->greeting("Hola, {$notifiable->name}")
            ->line("{$this->invitedBy} te ha dado acceso a ".config('app.name').'.')
            ->line('Para empezar, define tu contraseña con el siguiente botón.')
            ->action('Definir mi contraseña', $url)
            ->line('Este enlace caduca en '.self::INVITATION_HOURS.' horas. Si no esperabas esta invitación, ignora este correo.')
            ->salutation('Saludos');
    }
}
