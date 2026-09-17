<?php

namespace App\Mail;

use App\Models\Alumni;
use App\Models\JobPosting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewJobPostingMail extends Mailable implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use Queueable, SerializesModels;

    public JobPosting $job;
    public Alumni $alumni;

    /**
     * @param JobPosting $job    The newly posted job (status = ACTIVE at send time).
     * @param Alumni     $alumni The recipient — used for the greeting and,
     *                           if you want it later, per-alumni tracking.
     */
    public function __construct(JobPosting $job, Alumni $alumni)
    {
        $this->job    = $job;
        $this->alumni = $alumni;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Job Opportunity: ' . $this->job->job_title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-job-posting',
            with: [
                'job'      => $this->job,
                'alumni'   => $this->alumni,
                // Deep-link straight into the same detail view your
                // job-opportunities.blade.php already opens via ?job=ID
                // in mount(). Route name confirmed from web.php:
                // Route::view('/job/opportunities', ...)->name('job.opportunities');
                'jobUrl'   => route('job.opportunities', ['job' => $this->job->id]),
            ],
        );
    }
}