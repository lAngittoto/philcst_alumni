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

    public function __construct(
        public Organizer $organizer,
        public string $password
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to PHILCST Alumni System - Organizer Account Created',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.organizer-registered',
            with: [
                'organizer' => $this->organizer,
                'password'  => $this->password,
                'loginUrl'  => config('app.url') . '/login',
            ],
        );
    }
}