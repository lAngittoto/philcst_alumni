<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AlumniLoginAlert extends Mailable
{
    use Queueable, SerializesModels;

    public string $fullName;
    public int    $attempts;
    public string $ipAddress;
    public string $device;
    public string $attemptedAt;
    public int    $lockMinutes;

    public function __construct(
        string $fullName,
        int    $attempts,
        string $ipAddress,
        string $device,
        string $attemptedAt,
        int    $lockMinutes
    ) {
        $this->fullName    = $fullName;
        $this->attempts    = $attempts;
        $this->ipAddress   = $ipAddress;
        $this->device      = $device;
        $this->attemptedAt = $attemptedAt;
        $this->lockMinutes = $lockMinutes;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Security Alert: Multiple Failed Login Attempts – Philcst Alumni Connect'
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.alumni-login-alert',
            with: [
                'fullName'    => $this->fullName,
                'attempts'    => $this->attempts,
                'ipAddress'   => $this->ipAddress,
                'device'      => $this->device,
                'attemptedAt' => $this->attemptedAt,
                'lockMinutes' => $this->lockMinutes,
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