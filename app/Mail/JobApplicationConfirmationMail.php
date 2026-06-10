<?php

namespace App\Mail;

use App\Models\JobApplication;
use App\Models\JobListing;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class JobApplicationConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public JobApplication $application,
        public JobListing     $job,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Application Received — ' . $this->job->title);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.job-application-confirmation');
    }
}
