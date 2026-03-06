<?php

namespace App\Mail;

use App\Models\Organizer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrganizerPasswordReset extends Mailable
{
    // NOTE: ShouldQueue is intentionally REMOVED.
    // Queued mail requires `php artisan queue:work` to be running.
    // Without a queue worker, OTPs would never be delivered.
    // We send synchronously so the user gets the OTP immediately.
    use Queueable, SerializesModels;

    public function __construct(
        public Organizer $organizer,
        public string $otp,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Password Change Verification - PhilCST Alumni Portal',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.organizer-password-reset',
            with: [
                'organizer' => $this->organizer,
                'otp'       => $this->otp,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}