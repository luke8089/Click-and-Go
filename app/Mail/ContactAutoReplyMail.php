<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactAutoReplyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $contactSubject,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'We Received Your Message – Click & Go');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.contact-auto-reply');
    }
}
