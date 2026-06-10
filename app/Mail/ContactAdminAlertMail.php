<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactAdminAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string  $name,
        public string  $email,
        public string  $contactSubject,
        public string  $message,
        public ?string $phone = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'New Contact Message: ' . $this->contactSubject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.contact-admin-alert');
    }
}
