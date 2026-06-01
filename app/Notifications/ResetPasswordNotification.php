<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The password reset token.
     */
    public string $token;

    /**
     * The email address for the reset link.
     */
    public string $email;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $token, string $email)
    {
        $this->token = $token;
        $this->email = $email;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $resetUrl = $this->buildResetUrl();
        $expirationMinutes = config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset Your Password')
            ->greeting('Hello '.$notifiable->name.'!')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', $resetUrl)
            ->line("This password reset link will expire in {$expirationMinutes} minutes.")
            ->line('If you did not request a password reset, no further action is required.')
            ->salutation('Regards, The SaaSForge Team');
    }

    /**
     * Build the password reset URL.
     */
    protected function buildResetUrl(): string
    {
        // Use frontend URL from config, fallback to app URL
        $frontendUrl = config('app.frontend_url', config('app.url'));

        return $frontendUrl.'/reset-password?token='.$this->token.'&email='.urlencode($this->email);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'token' => $this->token,
            'email' => $this->email,
        ];
    }
}
