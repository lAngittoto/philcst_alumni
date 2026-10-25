<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Generic password-reset OTP mailable used for ALL roles
 * (alumni, organizer, registrar, director, admin).
 *
 * Replaces the alumni-only App\Mail\AlumniPasswordReset for the unified
 * forgot-password flow. AlumniPasswordReset is left untouched/unused so
 * nothing else that may still reference it breaks.
 */
class UserPasswordReset extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $fullName,
        public readonly string $otp,
        public readonly string $roleLabel, // e.g. "Alumni", "Organizer", "Registrar", "Director", "Admin"
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Password Reset Verification Code — PhilCST Portal',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.user-password-reset',
        );
    }
}