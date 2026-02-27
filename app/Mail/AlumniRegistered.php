<?php

namespace App\Mail;

use App\Models\Alumni;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AlumniRegistered extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Alumni $alumni,
        public string $temporaryPassword
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to Philcst Alumni Connect - Account Created Successfully',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.alumni-registered',
            with: [
                'alumni'            => $this->alumni,
                'temporaryPassword' => $this->temporaryPassword,
                'studentId'         => $this->alumni->student_id,
                'loginUrl'          => config('app.url') . '/login',
            ],
        );
    }
}