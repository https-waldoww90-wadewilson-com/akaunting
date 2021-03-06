<?php

namespace App\Jobs\Banking;

use App\Abstracts\Job;
use App\Jobs\Banking\CreateTransaction;
use App\Jobs\Expense\CreateBillHistory;
use App\Jobs\Income\CreateInvoiceHistory;
use App\Models\Banking\Transaction;
use App\Models\Income\Invoice;
use App\Models\Setting\Currency;
use Date;

class CreateDocumentTransaction extends Job
{
    protected $model;

    protected $request;

    /**
     * Create a new job instance.
     *
     * @param  $model
     * @param  $request
     */
    public function __construct($model, $request)
    {
        $this->model = $model;
        $this->request = $this->getRequestInstance($request);
    }

    /**
     * Execute the job.
     *
     * @return Transaction
     */
    public function handle()
    {
        $this->prepareRequest();

        $this->checkAmount();

        $transaction = $this->dispatch(new CreateTransaction($this->request));

        // Upload attachment
        if ($this->request->file('attachment')) {
            $media = $this->getMedia($this->request->file('attachment'), 'transactions');

            $transaction->attachMedia($media, 'attachment');
        }

        $this->model->save();

        $this->createHistory($transaction);

        return $transaction;
    }

    protected function prepareRequest()
    {
        if ($this->request->missing('currency_code')) {
            $this->request['currency_code'] = $this->model->currency_code;
        }

        $this->currency = Currency::where('code', $this->request['currency_code'])->first();

        $this->request['type'] = ($this->model instanceof Invoice) ? 'income' : 'expense';
        $this->request['currency_rate'] = $this->currency->rate;

        $this->request['amount'] = isset($this->request['amount']) ? $this->request['amount'] : ($this->model->amount - $this->getPaidAmount());
        $this->request['paid_at'] = isset($this->request['paid_at']) ? $this->request['paid_at'] : Date::now()->format('Y-m-d');
        $this->request['company_id'] = isset($this->request['company_id']) ? $this->request['company_id'] : session('company_id');
        $this->request['account_id'] = isset($this->request['account_id']) ? $this->request['account_id'] : setting('default.account');
        $this->request['payment_method'] = isset($this->request['payment_method']) ? $this->request['payment_method'] : setting('default.payment_method');
        $this->request['currency_code'] = isset($this->request['currency_code']) ? $this->request['currency_code'] : $this->model->currency_code;
        $this->request['document_id'] = isset($this->request['document_id']) ? $this->request['document_id'] : $this->model->id;
        $this->request['contact_id'] = isset($this->request['contact_id']) ? $this->request['contact_id'] : $this->model->contact_id;
        $this->request['category_id'] = isset($this->request['category_id']) ? $this->request['category_id'] : $this->model->category_id;
        $this->request['notify'] = isset($this->request['notify']) ? $this->request['notify'] : 0;
    }

    protected function checkAmount()
    {
        $currencies = Currency::enabled()->pluck('rate', 'code')->toArray();

        $total_amount = $this->model->amount;

        $default_amount = (double) $this->request['amount'];

        if ($this->model->currency_code == $this->request['currency_code']) {
            $amount = $default_amount;
        } else {
            $default_amount_model = new Transaction();
            $default_amount_model->default_currency_code = $this->model->currency_code;
            $default_amount_model->amount                = $default_amount;
            $default_amount_model->currency_code         = $this->request['currency_code'];
            $default_amount_model->currency_rate         = $currencies[$this->request['currency_code']];

            $default_amount = (double) $default_amount_model->getDivideConvertedAmount();

            $convert_amount_model = new Transaction();
            $convert_amount_model->default_currency_code = $this->request['currency_code'];
            $convert_amount_model->amount = $default_amount;
            $convert_amount_model->currency_code = $this->model->currency_code;
            $convert_amount_model->currency_rate = $currencies[$this->model->currency_code];

            $amount = (double) $convert_amount_model->getAmountConvertedFromCustomDefault();
        }

        $total_amount -= $this->getPaidAmount();

        // For amount cover integer
        $multiplier = 1;

        for ($i = 0; $i < $this->currency->precision; $i++) {
            $multiplier *= 10;
        }

        $amount_check = (int) ($amount * $multiplier);
        $total_amount_check = (int) (round($total_amount, $this->currency->precision) * $multiplier);

        if ($amount_check > $total_amount_check) {
            $error_amount = $total_amount;

            if ($this->model->currency_code != $this->request['currency_code']) {
                $error_amount_model = new Transaction();
                $error_amount_model->default_currency_code = $this->request['currency_code'];
                $error_amount_model->amount                = $error_amount;
                $error_amount_model->currency_code         = $this->model->currency_code;
                $error_amount_model->currency_rate         = $currencies[$this->model->currency_code];

                $error_amount = (double) $error_amount_model->getDivideConvertedAmount();

                $convert_amount_model = new Transaction();
                $convert_amount_model->default_currency_code = $this->model->currency_code;
                $convert_amount_model->amount = $error_amount;
                $convert_amount_model->currency_code = $this->request['currency_code'];
                $convert_amount_model->currency_rate = $currencies[$this->request['currency_code']];

                $error_amount = (double) $convert_amount_model->getAmountConvertedFromCustomDefault();
            }

            $message = trans('messages.error.over_payment', ['amount' => money($error_amount, $this->request['currency_code'], true)]);

            throw new \Exception($message);
        } else {
            $this->setStatusCode($amount_check, $total_amount_check);
        }

        return true;
    }

    protected function getPaidAmount()
    {
        $paid = 0;

        if (!$this->model->transactions->count()) {
            return $paid;
        }

        $currencies = Currency::enabled()->pluck('rate', 'code')->toArray();

        foreach ($this->model->transactions as $item) {
            $default_amount = $item->amount;

            if ($this->model->currency_code == $item->currency_code) {
                $amount = (double) $default_amount;
            } else {
                $default_amount_model = new Transaction();
                $default_amount_model->default_currency_code = $this->model->currency_code;
                $default_amount_model->amount = $default_amount;
                $default_amount_model->currency_code = $item->currency_code;
                $default_amount_model->currency_rate = $currencies[$item->currency_code];

                $default_amount = (double) $default_amount_model->getDivideConvertedAmount();

                $convert_amount_model = new Transaction();
                $convert_amount_model->default_currency_code = $item->currency_code;
                $convert_amount_model->amount = $default_amount;
                $convert_amount_model->currency_code = $this->model->currency_code;
                $convert_amount_model->currency_rate = $currencies[$this->model->currency_code];

                $amount = (double) $convert_amount_model->getAmountConvertedFromCustomDefault();
            }

            $paid += $amount;
        }

        return $paid;
    }

    protected function setStatusCode($amount_check, $total_amount_check)
    {
        $column = ($this->model instanceof Invoice) ? 'invoice_status_code' : 'bill_status_code';

        if ($amount_check == $total_amount_check) {
            $this->model->$column = 'paid';
        } else {
            $this->model->$column = 'partial';
        }
    }

    protected function createHistory($transaction)
    {
        $history_desc = money((double) $transaction->amount, (string) $transaction->currency_code, true)->format() . ' ' . trans_choice('general.payments', 1);

        if ($this->model instanceof Invoice) {
            $this->dispatch(new CreateInvoiceHistory($this->model, 0, $history_desc));
        } else {
            $this->dispatch(new CreateBillHistory($this->model, 0, $history_desc));
        }
    }
}
