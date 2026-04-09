<?php

namespace App\Mail;

use App\Models\Alumni;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AlumniPasswordReset extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Alumni $alumni,
        public readonly string $otp,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Email Verification Code — PhilCST Alumni Connect',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.alumni-password-reset',
        );
    }
}