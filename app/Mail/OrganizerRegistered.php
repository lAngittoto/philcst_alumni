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
        // Build full name manually from split fields
        $fullName = trim(implode(' ', array_filter([
            $this->organizer->first_name,
            $this->organizer->middle_initial ?: null,
            $this->organizer->last_name,
            $this->organizer->suffix         ?: null,
        ])));

        return new Content(
            view: 'emails.organizer-registered',
            with: [
                'organizer'    => $this->organizer,
                'idNumber'     => $this->organizer->id_number,
                'tempPassword' => $this->tempPassword,
                'loginUrl'     => url('/login'),
                'name'         => $fullName,        // ← computed manually
                'email'        => $this->organizer->email,
                'department'   => $this->organizer->department,
            ],
        );
    }
}