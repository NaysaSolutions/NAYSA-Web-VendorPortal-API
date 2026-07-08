<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VendorRegistrationAccessMail extends Mailable
{
    use Queueable, SerializesModels;

    public array $mailData;

    public function __construct(array $mailData)
    {
        $this->mailData = $mailData;
    }

    public function build()
    {
        return $this
            ->subject('Vendor Portal Registration - ' . $this->mailData['reg_no'])
            ->view('emails.vendor-registration-access')
            ->with(['mailData' => $this->mailData]);
    }
}
