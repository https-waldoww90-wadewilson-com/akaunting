<?php

namespace App\Listeners\Income;

use App\Events\Income\PaymentReceived as Event;
use App\Jobs\Banking\CreateDocumentTransaction;
use App\Traits\Jobs;

class CreateInvoiceTransaction
{
    use Jobs;

    /**
     * Handle the event.
     *
     * @param  $event
     * @return array
     */
    public function handle(Event $event)
    {
        $invoice = $event->invoice;
        $request = $event->request;

        try {
            $this->dispatch(new CreateDocumentTransaction($invoice, $request));
        } catch (\Exception $e) {
            $message = $e->getMessage();

            if (!$user = user()) {
                flash($message)->error();
    
                redirect()->route('signed.invoices.show', $invoice->id)->send();
            }

            if ($user->can('read-client-portal')) {
                flash($message)->error();

                redirect()->route('portal.invoices.show', $invoice->id)->send();
            }

            throw new \Exception($message);
        }
    }
}
