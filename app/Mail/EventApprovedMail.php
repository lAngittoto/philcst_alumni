<?php

namespace App\Mail;

use App\Models\AdminEvent;
use App\Models\Alumni;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EventApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public AdminEvent $event;
    public Alumni $alumni;

    /**
     * @param AdminEvent $event  The approved event.
     * @param Alumni     $alumni The recipient — used for the greeting.
     */
    public function __construct(AdminEvent $event, Alumni $alumni)
    {
        $this->event  = $event;
        $this->alumni = $alumni;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You\'re Invited: ' . $this->event->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.event-approved',
            with: [
                'event'    => $this->event,
                'alumni'   => $this->alumni,
                // Deep-link to the upcoming events page — ?event=ID opens
                // the detail modal directly, same as the director deep-link.
                'eventUrl' => route('upcoming.events', ['event' => $this->event->id]),
            ],
        );
    }
}