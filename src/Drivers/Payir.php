<?php

namespace Shetabit\Payment\Drivers;

use GuzzleHttp\Client;
use Shetabit\Payment\Abstracts\Driver;
use Shetabit\Payment\Exceptions\InvalidPaymentException;
use Shetabit\Payment\Invoice;

class Payir extends Driver
{
    /**
     * Payir Client.
     *
     * @var object
     */
    protected $client;

    /**
     * Invoice
     *
     * @var Invoice
     */
    protected $invoice;

    /**
     * Driver settings
     *
     * @var object
     */
    protected $settings;

    /**
     * Zarinpal constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
        $this->client = new Client();
    }

    /**
     * Retrieve data from details using its name.
     *
     * @return string
     */
    private function extractDetails($name)
    {
        return empty($this->invoice->getDetails()[$name]) ? null : $this->invoice->getDetails()[$name];
    }

    /**
     * Purchase Invoice.
     *
     * @return string
     */
    public function purchase()
    {
        $mobile = $this->extract('mobile');
        $description = $this->extract('description');
        $factorNumber = $this->extract('factorNumber');

        $data = array(
            'api' => $this->settings->merchantId,
            'amount' => $this->invoice->getAmount(),
            'redirect' => $this->settings->callbackUrl,
            'mobile' => $mobile,
            'description' => $description,
            'factorNumber' => $factorNumber,
        );

        $response = $this->client->request(
            'POST',
            $this->settings->apiPurchaseUrl,
            $data
        );
        $body = json_decode($response->getBody()->getContents(), true);

        if ($body['status'] == 1) {
            $this->invoice->transactionId($body['token']);
        }

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     *
     * @return \Illuminate\Http\RedirectResponse|mixed
     */
    public function pay()
    {
        $payUrl = $this->settings->apiPaymentUrl.$this->invoice->getTransactionId();

        // redirect using laravel logic
        return redirect()->to($payUrl);
    }

    /**
     * Verify payment
     *
     * @return mixed|void
     * @throws InvalidPaymentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function verify()
    {
        $data = [
            'api' => $this->settings->api,
            'token'  => $this->invoice->getTransactionId(),
        ];

        $response = $this->client->request(
            'POST',
            $this->settings->apiVerificationUrl,
            $data
        );
        $body = json_decode($response->getBody()->getContents(), true);

        if ($body['status'] == 0) {
            $this->notVerified($body['status']);
        }
    }

    /**
     * Trigger an exception
     *
     * @param $status
     * @throws InvalidPaymentException
     */
    private function notVerified($status)
    {
        $translations = array(
            "-1" => "ارسال api الزامی می باشد",
            "-2" => "کد تراکنش الزامی است",
            "-3" => "درگاه پرداختی با api ارسالی یافت نشد و یا غیر فعال می باشد",
            "-4" => "فروشنده غیر فعال می باشد",
            "-5" => "تراکنش با خطا مواجه شده است",
        );

        if (array_key_exists($status, $translations)) {
            throw new InvalidPaymentException($translations[$status]);
        } else {
            throw new InvalidPaymentException('خطای ناشناخته ای رخ داده است.');
        }
    }
}