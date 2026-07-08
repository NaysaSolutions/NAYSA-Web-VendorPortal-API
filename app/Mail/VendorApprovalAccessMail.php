<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VendorApprovalAccessMail extends Mailable
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
            ->subject('Vendor Accreditation Approved - ' . $this->mailData['vendor_code'])
            ->view('emails.vendor-approval-access')
            ->with(['mailData' => $this->mailData]);
    }
}
