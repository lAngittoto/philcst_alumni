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
    // NOTE: ShouldQueue removed — same reason as OrganizerPasswordReset.
    // Welcome emails must arrive immediately when admin creates the account.
    use Queueable, SerializesModels;

    public function __construct(
        public Organizer $organizer,
        public string $tempPassword,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to PHILCST Alumni System - Your Account Has Been Created',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.organizer-registered',
            with: [
                'organizer'    => $this->organizer,
                'idNumber'     => $this->organizer->id_number,
                'tempPassword' => $this->tempPassword,
                'loginUrl'     => url('/login'),
                'name'         => $this->organizer->name,
                'email'        => $this->organizer->email,
                'department'   => $this->organizer->department,
            ],
        );
    }
}