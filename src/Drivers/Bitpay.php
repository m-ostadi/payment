<?php

namespace Shetabit\Payment\Drivers;

use GuzzleHttp\Client;
use Shetabit\Payment\Abstracts\Driver;
use Shetabit\Payment\Exceptions\InvalidPaymentException;
use Shetabit\Payment\Invoice;

class Bitpay extends Driver
{
    /**
     * Bitpay Client.
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
     * Bitpay constructor.
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
     * Purchase Invoice.
     *
     * @return string
     */
    public function purchase()
    {
        $details = $this->invoice->getDetails();

        $data = array(
            'order_id' => $this->invoice->getUuid(),
            'amount' => $this->invoice->getAmount(),
            'name' => $details['name'] ?? null,
            'phone' => $details['mobile'] ?? $details['phone'] ?? null,
            'mail' => $details['email'] ?? null,
            'desc' => $details['description'] ?? $this->settings->description,
            'callback' => $this->settings->callbackUrl,
            'reseller' => $details['reseller'] ?? null,
        );

        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiPurchaseUrl,
                [
                    "json" => $data,
                    "headers" => [
                        'X-API-KEY' => $this->settings->merchantId,
                        'Content-Type' => 'application/json',
                        'X-SANDBOX' => (int) $this->settings->sandbox,
                    ],
                    "http_errors" => false,
                ]
            );

        $body = json_decode($response->getBody()->getContents(), true);

        if (empty($body['id'])) {
            // some error has happened
        } else {
            $this->invoice->transactionId($body['id']);
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
        $apiUrl =  $this->settings->apiPaymentUrl;

        // use sandbox url if we are in sandbox mode
        if (!empty($this->settings->sandbox)) {
            $apiUrl = $this->settings->apiSandboxPaymentUrl;
        }

        $payUrl = $apiUrl.$this->invoice->getTransactionId();

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
            'id' => $this->invoice->getTransactionId() ?? request()->input('id'),
            'order_id' => request()->input('order_id'),
        ];

        $response = $this->client->request(
            'POST',
            $this->settings->apiVerificationUrl,
            [
                'json' => $data,
                "headers" => [
                    'X-API-KEY' => $this->settings->merchantId,
                    'Content-Type' => 'application/json',
                    'X-SANDBOX' => (int) $this->settings->sandbox,
                ],
                "http_errors" => false,
            ]
        );
        $body = json_decode($response->getBody()->getContents(), true);

        if (isset($body['error_code']) || $body['status'] != 100) {
            $errorCode = $body['status'] ?? $body['error_code'];

            $this->notVerified($errorCode);
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
            "1" => "پرداخت انجام نشده است.",
            "2" => "پرداخت ناموفق بوده است.",
            "3" => "خطا رخ داده است.",
            "4" => "بلوکه شده.",
            "5" => "برگشت به پرداخت کننده.",
            "6" => "برگشت خورده سیستمی.",
            "10" => "در انتظار تایید پرداخت.",
            "100" => "پرداخت تایید شده است.",
            "101" => "پرداخت قبلا تایید شده است.",
            "200" => "به دریافت کننده واریز شد.",
            "11" => "کاربر مسدود شده است.",
            "12" => "API Key یافت نشد.",
            "13" => "درخواست شما از {ip} ارسال شده است. این IP با IP های ثبت شده در وب سرویس همخوانی ندارد.",
            "14" => "وب سرویس تایید نشده است.",
            "21" => "حساب بانکی متصل به وب سرویس تایید نشده است.",
            "31" => "کد تراکنش id نباید خالی باشد.",
            "32" => "شماره سفارش order_id نباید خالی باشد.",
            "33" => "مبلغ amount نباید خالی باشد.",
            "34" => "مبلغ amount باید بیشتر از {min-amount} ریال باشد.",
            "35" => "مبلغ amount باید کمتر از {max-amount} ریال باشد.",
            "36" => "مبلغ amount بیشتر از حد مجاز است.",
            "37" => "آدرس بازگشت callback نباید خالی باشد.",
            "38" => "درخواست شما از آدرس {domain} ارسال شده است. دامنه آدرس بازگشت callback با آدرس ثبت شده در وب سرویس همخوانی ندارد.",
            "51" => "تراکنش ایجاد نشد.",
            "52" => "استعلام نتیجه ای نداشت.",
            "53" => "تایید پرداخت امکان پذیر نیست.",
            "54" => "مدت زمان تایید پرداخت سپری شده است.",
        );
        if (array_key_exists($status, $translations)) {
            throw new InvalidPaymentException($translations[$status]);
        } else {
            throw new InvalidPaymentException('خطای ناشناخته ای رخ داده است.');
        }
    }
}