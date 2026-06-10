<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User   $user,
        public string $time,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Password Was Changed – Click & Go');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.password-changed');
    }
}
