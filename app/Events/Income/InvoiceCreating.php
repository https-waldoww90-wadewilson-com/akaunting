<?php

namespace App\Events\Income;

use Illuminate\Queue\SerializesModels;

class InvoiceCreating
{
    use SerializesModels;

    public $request;

    /**
     * Create a new event instance.
     *
     * @param $request
     */
    public function __construct($request)
    {
        $this->request = $request;
    }
}
