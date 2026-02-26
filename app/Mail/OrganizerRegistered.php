<?php

namespace App\Mail;

use App\Models\Organizer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrganizerRegistered extends Mailable
{
    use Queueable, SerializesModels;

    public Organizer $organizer;
    public string $password;

    /**
     * Create a new message instance.
     */
    public function __construct(Organizer $organizer, string $password)
    {
        $this->organizer = $organizer;
        $this->password  = $password;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to PHILCST Alumni System - Organizer Account Created',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.organizer-registered',
            with: [
                'organizer' => $this->organizer,
                'password'  => $this->password,
            ],
        );
    }
}