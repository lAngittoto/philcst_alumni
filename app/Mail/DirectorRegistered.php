<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DirectorRegistered extends Mailable
{
    use Queueable, SerializesModels;

    public string $fullName;
    public string $username;
    public string $tempPassword;
    public string $email;
    /**
     * 'created'       → new director account was registered
     * 'email_updated' → admin changed the director's email; new temp password issued
     */
    public string $type;

    /**
     * Create a new message instance.
     *
     * @param string $type  'created' | 'email_updated'
     */
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

    /**
     * Get the message envelope.
     * Subject differs per type so the director's inbox makes it clear
     * whether this is an account creation or a credential reset.
     */
    public function envelope(): Envelope
    {
        $subject = $this->type === 'email_updated'
            ? 'Your Email & Credentials Were Updated – Philcst Alumni Connect'
            : 'Your Director Account – Philcst Alumni Connect';

        return new Envelope(subject: $subject);
    }

    /**
     * Get the message content definition.
     * Both types share the same blade view; $type drives what it renders.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.director-registered',
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
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}