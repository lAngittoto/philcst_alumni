<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminRegistered extends Mailable
{
    use Queueable, SerializesModels;

    public string $fullName;
    public string $username;
    public string $tempPassword;
    public string $email;
    /**
     * 'created'       → new admin account was registered
     * 'email_updated' → admin's email was changed; new temp password issued
     */
    public string $type;

    public function __construct(
        string $fullName,
        string $username,
        string $tempPassword,
        string $email,
        string $type = 'created'
    ) {
        $this->fullName     = $fullName;
        $this->username     = $username;
        $this->tempPassword = $tempPassword;
        $this->email        = $email;
        $this->type         = $type;
    }

    public function envelope(): Envelope
    {
        $subject = $this->type === 'email_updated'
            ? 'Your Email & Credentials Were Updated – Philcst Alumni Connect'
            : 'Your Admin Account – Philcst Alumni Connect';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-registered',
            with: [
                'fullName'     => $this->fullName,
                'username'     => $this->username,
                'tempPassword' => $this->tempPassword,
                'email'        => $this->email,
                'type'         => $this->type,
            ],
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}