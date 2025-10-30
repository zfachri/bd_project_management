<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OTPMail extends Mailable
{
    use Queueable, SerializesModels;

    public $fullName;
    public $otpCode;
    public $expiresIn;
    public $purpose;

    /**
     * Create a new message instance.
     */
    public function __construct($fullName, $otpCode, $expiresIn, $purpose = 'login')
    {
        $this->fullName = $fullName;
        $this->otpCode = $otpCode;
        $this->expiresIn = $expiresIn;
        $this->purpose = $purpose;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->purpose === 'reset_password' 
            ? 'Reset Password OTP Code' 
            : 'Login Verification OTP Code';

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.otp',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}