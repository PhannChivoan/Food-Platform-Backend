<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InviteUserMail extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;
    public string $password;

    public function __construct(User $user, string $password)
    {
        $this->user = $user;
        $this->password = $password;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You've been invited to join " . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.invite',
            with: [
                'userName' => $this->user->name,
                'userEmail' => $this->user->email,
                'userRole' => $this->user->role,
                'password' => $this->password,
                'loginUrl' => url('/login'),
                'appName' => config('app.name'),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
