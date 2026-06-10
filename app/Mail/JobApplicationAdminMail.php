<?php

namespace App\Mail;

use App\Models\JobApplication;
use App\Models\JobListing;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class JobApplicationAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public JobApplication $application,
        public JobListing     $job,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'New Job Application: ' . $this->job->title);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.job-application-admin');
    }
}
