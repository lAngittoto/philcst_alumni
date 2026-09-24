<?php

namespace App\Mail;

use App\Models\AdminEvent;
use App\Models\Alumni;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
        // Guard: route() will throw if 'upcoming.events' is not defined —
        // catch it here so a missing route doesn't silently kill the whole
        // mail send and leave the alumni without an invite.
        try {
            $eventUrl = route('upcoming.events', ['event' => $this->event->id]);
        } catch (\Throwable $e) {
            Log::warning('[EventApprovedMail] Could not build eventUrl — route "upcoming.events" may not be defined.', [
                'event_id' => $this->event->id,
                'error'    => $e->getMessage(),
            ]);
            // Fall back to the app root so the email still renders and sends
            // rather than crashing mid-loop and skipping all remaining alumni.
            $eventUrl = config('app.url', '/');
        }

        return new Content(
            view: 'emails.event-approved',
            with: [
                'event'    => $this->event,
                'alumni'   => $this->alumni,
                // Deep-link to the upcoming events page — ?event=ID opens
                // the detail modal directly, same as the director deep-link.
                'eventUrl' => $eventUrl,
            ],
        );
    }
}