<?php

namespace App\Notifications;

use App\Models\WorkspaceInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected WorkspaceInvitation $invitation,
        protected string $rawToken
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->invitation->loadMissing(['workspace', 'inviter']);

        $appUrl = rtrim(config('app.url'), '/');
        $acceptUrl = "{$appUrl}/invitations/accept/{$this->rawToken}";
        $declineUrl = "{$appUrl}/api/invitations/{$this->rawToken}/decline";

        return (new MailMessage)
            ->subject("You've been invited to join {$this->invitation->workspace->name}")
            ->view('emails.workspace-invitation', [
                'workspaceName' => $this->invitation->workspace->name,
                'inviterName' => $this->invitation->inviter?->name ?? 'A teammate',
                'role' => $this->invitation->role,
                'expiresAt' => $this->invitation->expires_at,
                'acceptUrl' => $acceptUrl,
                'declineUrl' => $declineUrl,
            ]);
    }
}
