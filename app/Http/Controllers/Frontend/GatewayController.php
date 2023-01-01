<?php

namespace App\Http\Controllers\Frontend;

use App\Enums\GatewayType;
use App\Enums\InvestStatus;
use App\Enums\TxnStatus;
use App\Enums\TxnType;
use App\Http\Controllers\Controller;
use App\Models\Gateway;
use App\Models\Invest;
use App\Models\Transaction;
use charlesassets\LaravelPerfectMoney\PerfectMoney;
use Crypt;
use Illuminate\Http\Request;
use Mollie\Laravel\Facades\Mollie;
use Paystack;
use Session;
use Shakurov\Coinbase\Facades\Coinbase;
use Srmklive\PayPal\Services\PayPal as PayPalClient;
use Txn;
use URL;

class GatewayController extends Controller
{
    public function gateway($code)
    {
        $Gateway = Gateway::code($code)->select('name', 'charge', 'minimum_deposit', 'maximum_deposit', 'charge_type', 'logo', 'type')->first();

        if ($Gateway->type == GatewayType::Manual) {
            $Gateway = Gateway::code($code)->select('name', 'charge', 'minimum_deposit', 'maximum_deposit', 'charge_type', 'logo', 'type', 'credentials', 'payment_details')->first();

            $credentials = $Gateway->credentials;
            $paymentDetails = $Gateway->payment_details;

            $Gateway = array_merge($Gateway->toArray(), ['credentials' => view('frontend.gateway.include.manual', compact('credentials', 'paymentDetails'))->render()]);
        }

        return $Gateway;
    }

    //list json

    public function gatewayList()
    {
        $gateways = Gateway::all();
        return view('frontend.gateway.include.__list', compact('gateways'));
    }

    //  Paypal Config

    public function paypalGateway(Request $request)
    {


        $depositTnx = Session::get('deposit_tnx');
        $tnxInfo = Transaction::tnx($depositTnx);


        $provider = new PayPalClient;
        $provider->setApiCredentials(config('paypal'));
        $paypalToken = $provider->getAccessToken();

        $response = $provider->createOrder([
            "intent" => "CAPTURE",
            "application_context" => [
                "return_url" => route('gateway.paypal.success'),
                "cancel_url" => route('gateway.paypal.cancel'),
            ],
            "purchase_units" => [
                0 => [
                    "amount" => [
                        "currency_code" => $tnxInfo->pay_currency,
                        "value" => $tnxInfo->pay_amount,
                    ],
                    'reference_id' => $depositTnx,

                ]
            ],
        ]);

        if (isset($response['id']) && $response['id'] != null) {

            // redirect to approve href
            foreach ($response['links'] as $links) {
                if ($links['rel'] == 'approve') {
                    return redirect()->away($links['href']);
                }
            }

            return redirect()
                ->route('user.dashboard')
                ->with('error', 'Something went wrong.');

        } else {
            return redirect()
                ->route('user.dashboard')
                ->with('error', $response['message'] ?? 'Something went wrong.');
        }
    }

    public function paypalSuccess(Request $request)
    {
        $provider = new PayPalClient;
        $provider->setApiCredentials(config('paypal'));
        $provider->getAccessToken();
        $response = $provider->capturePaymentOrder($request['token']);


        if (isset($response['status']) && $response['status'] == 'COMPLETED') {
            $txn = $response['purchase_units'][0]['reference_id'];

            return self::paymentSuccess($txn);

        } else {
            return redirect()
                ->route('user.deposit.now')
                ->with('error', $response['message'] ?? 'Something went wrong.');
        }
    }

    public function paypalCancel()
    {
        return redirect(route('status.cancel'));
    }

    private function paymentSuccess($ref,$userId=null)
    {
        $txnInfo = Transaction::tnx($ref);
        if ($txnInfo->type == TxnType::Investment) {

            $investmentInfo = Invest::where('transaction_id', $txnInfo->id)->first();
            $investmentInfo->update([
                'status' => InvestStatus::Ongoing,
                'created_at' => now(),
            ]);

            $txnInfo->update([
                'status' => TxnStatus::Success,
            ]);

                notify()->success('Successfully Investment', 'success');
                return redirect()->route('user.invest-logs');

        } else {

            $txnInfo->update([
                'status' => TxnStatus::Success,
            ]);
            Txn::update($ref, 'success',$userId);
            return redirect(URL::temporarySignedRoute(
                'status.success', now()->addMinutes(2)
            ));
        }
    }



//    ************************************* stripe Config **********************************************************

    public function stripeGateway(Request $request)
    {


        $ref = Crypt::decryptString($request->reftrn);

        return self::paymentSuccess($ref);

    }


    public function perfectMoney(Request $request)
    {
        $ref = Crypt::decryptString($request->PAYMENT_ID);
        return self::paymentSuccess($ref);
    }


    // mollie
    public function mollieGateway(Request $request)
    {

        $paymentId = Session::get('m_id');
        $payment = Mollie::api()->payments()->get($paymentId);


        if ($payment->isPaid()) {
            $ref = Crypt::decryptString($request->reftrn);
            return self::paymentSuccess($ref);
        }

        return redirect(route('status.cancel'));

    }


    //coinbase
    public function coinbase(Request $request)
    {
        $ref = Crypt::decryptString($request->reftrn);
        return self::paymentSuccess($ref);
    }


    //paystack

    public function paystackCallback()
    {
        $paymentDetails = Paystack::getPaymentData();

        if ($paymentDetails['data']['status'] == 'success') {

            $transactionId = $paymentDetails['data']['reference'];

            return self::paymentSuccess($transactionId);


        } else {
            return redirect()->route('status.cancel');
        }
    }

    //voguepaySuccess

    public function voguepaySuccess(Request $request)
    {
        $ref = Crypt::decryptString($request->reftrn);
        return self::paymentSuccess($ref);
    }

    //flutterwaveSuccess
    public function flutterwaveProcess(Request $request)
    {



        if(isset($_GET['status']))
        {
            //* check payment status


            $txnid = $_GET['tx_ref'];
            $txnInfo = Transaction::tnx($txnid);

            if($_GET['status'] == 'cancelled')
            {
                // echo 'YOu cancel the payment';
                $txnInfo->update([
                    'status' => TxnStatus::Failed,
                ]);

                if ($txnInfo->type == TxnType::Investment) {

                    notify()->warning('YOu cancel the payment', 'cancelled');
                    return redirect()->route('user.invest-logs');

                } else {

                    notify()->warning('YOu cancel the payment', 'cancelled');
                    return redirect()->route('user.deposit.now');
                }
            }
            elseif($_GET['status'] == 'successful')
            {

                $txid = $_GET['transaction_id'];
                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_URL => "https://api.flutterwave.com/v3/transactions/{$txid}/verify",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => "",
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => "GET",
                    CURLOPT_HTTPHEADER => array(
                        "Content-Type: application/json",
                        "Authorization: Bearer FLWSECK_TEST-efc192c9a48969fc259c517aef8bcc82-X"
                    ),
                ));

                $response = curl_exec($curl);

                curl_close($curl);

                $res = json_decode($response);


                if($res->status)
                {
                    $amountPaid = $res->data->charged_amount;
                    $amountToPay = $res->data->meta->price;
                    if($amountPaid >= $amountToPay)
                    {
                        return self::paymentSuccess($txnid);
                    }
                    else
                    {

                        $txnInfo->update([
                            'status' => TxnStatus::Failed,
                        ]);

                        if ($txnInfo->type == TxnType::Investment) {

                            notify()->warning('Fraud transactio detected', 'detected');
                            return redirect()->route('user.invest-logs');

                        } else {

                            notify()->warning('Fraud transactio detected', 'detected');
                            return redirect()->route('user.deposit.now');
                        }
                    }
                }
                else
                {

                    $txnInfo->update([
                        'status' => TxnStatus::Failed,
                    ]);

                    if ($txnInfo->type == TxnType::Investment) {

                        notify()->warning('Can not process payment', 'not process');
                        return redirect()->route('user.invest-logs');

                    } else {

                        notify()->warning('Can not process payment', 'not process');
                        return redirect()->route('user.deposit.now');
                    }

                }
            }
        }



    }

    //congate callbak

    public function coingateProcess(Request $request)
    {

        if ($request->status == 'paid'){
            self::paymentSuccess($request->order_id,$request->user_id);
        }else{
            Txn::update($request->order_id, 'failed',$request->user_id);
        }
    }
    public function coingateSuccess()
    {
        return redirect(URL::temporarySignedRoute(
            'status.success', now()->addMinutes(2)
        ));
    }
    public function coingateCancel(Request $request)
    {
        return redirect()->route('status.cancel');
    }

    public function manualGateway(Request $request)
    {

        \App\Models\Transaction::find($request->transaction_id)->update([
            'description' => 'TRNX : ' . $request->prof_transaction,
            'type' => 'manual_deposit',
        ]);

        notify()->success('Awaiting Deposit Approval.', 'success');
        return redirect()->route('user.dashboard');
    }

    protected function paypal()
    {
        $data['view'] = 'frontend.gateway.submit.paypal';
        $data['action'] = route('gateway.paypal');
        $data['account'] = 'post';
        return $data;
    }

    protected function voguepaySubmit($info)
    {
        $data['info'] = $info;
        $data['view'] = 'frontend.gateway.submit.voguepay';
        $data['action'] = 'https://pay.voguepay.com';
        $data['method'] = 'POST';
        return $data;
    }

    protected function flutterwave($info)
    {
        //* Ca;; f;iterwave emdpoint
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api.flutterwave.com/v3/payments',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($info),
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer FLWSECK_TEST-efc192c9a48969fc259c517aef8bcc82-X',
                'Content-Type: application/json'
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);

        $res = json_decode($response);


        if($res->status == 'success')
        {
            $link = $res->data->link;
            return redirect($link);
        }
        else
        {
            $txnInfo = Transaction::tnx($info['tx_ref']);
            // echo 'YOu cancel the payment';
            $txnInfo->update([
                'status' => TxnStatus::Failed,
            ]);

            if ($txnInfo->type == TxnType::Investment) {

                notify()->warning('We can not process your payment', 'can not process');
                return redirect()->route('user.invest-logs');

            } else {

                notify()->warning('We can not process your payment', 'can not process');
                return redirect()->route('user.deposit.now');
            }
        }
    }


    protected function directGateway($gateway, $txnInfo)
    {

        $txn = $txnInfo->tnx;
        Session::put('deposit_tnx', $txn);

        if ($gateway == 'paypal') {
            $data = self::paypal();
        }
        elseif ($gateway == 'stripe') {


            $stripeCredential = gateway_info('stripe');


            \Stripe\Stripe::setApiKey($stripeCredential->stripe_secret);

            $session = \Stripe\Checkout\Session::create([
                'line_items' => [[
                    'price_data' => [
                        'currency' => $txnInfo->pay_currency,
                        'product_data' => [
                            'name' => $txnInfo->description,
                        ],
                        'unit_amount' => $txnInfo->pay_amount,
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => route('gateway.stripe', ['reftrn' => Crypt::encryptString($txnInfo->tnx)]),
                'cancel_url' => route('status.cancel'),
            ]);
            return redirect($session->url);
        }
        elseif ($gateway == 'mollie') {

            $payment = Mollie::api()->payments()->create([
                'amount' => [
                    'currency' => $txnInfo->pay_currency, // Type of currency you want to send
                    'value' => (string)$txnInfo->pay_amount . '.00', // You must send the correct number of decimals, thus we enforce the use of strings
                ],
                'description' => $txnInfo->description,
                'redirectUrl' => route('gateway.mollie', 'reftrn=' . Crypt::encryptString($txn)),
            ]);


            Session::put('m_id', $payment->id);
            $payment = Mollie::api()->payments()->get($payment->id);

            // redirect customer to Mollie checkout page
            return redirect($payment->getCheckoutUrl(), 303);
        }
        elseif ($gateway == 'perfectmoney') {

            $paymentUrl = route('gateway.perfectMoney');
            $noPaymentUrl = route('status.cancel');
            return PerfectMoney::render(['PAYMENT_AMOUNT' => $txnInfo->pay_amount, 'PAYMENT_ID' => $txn, 'PAYMENT_URL' => $paymentUrl, 'PAYMENT_UNITS' => $txnInfo->pay_currency, 'NOPAYMENT_URL' => $noPaymentUrl, 'NOPAYMENT_URL_METHOD' => 'GET']);

        }
        elseif ($gateway == 'coinbase') {

            $charge = Coinbase::createCharge([
                'name' => 'Deposit no #' . $txn,
                'description' => 'Deposit',
                "cancel_url" => route('status.cancel'),

                'local_price' => [
                    'amount' => $txnInfo->pay_amount,
                    'currency' => $txnInfo->pay_currency,
                ],
                'pricing_type' => 'fixed_price',
                'redirect_url' => route('gateway.coinbase', 'reftrn=' . Crypt::encryptString($txn))
            ]);

            return redirect($charge['data']['hosted_url']);

        }
        elseif ($gateway == 'paystack') {

            $data = array(
                "amount" => $txnInfo->pay_amount,
                "reference" => $txn,
                "email" => auth()->user()->email,
                "currency" => $txnInfo->pay_currency,
                "orderID" => $txn,
            );

            return \Unicodeveloper\Paystack\Facades\Paystack::getAuthorizationUrl($data)->redirectNow();


        }
        elseif ($gateway == 'voguepay') {
            $info = [
                'merchant_id' => gateway_info('voguepay')->merchant_id,
                'email' => auth()->user()->email,
                'amount' => $txnInfo->pay_amount,
                'currency' => $txnInfo->pay_currency,
                'success_url' => route('gateway.voguepay.success', 'reftrn=' . Crypt::encryptString($txn)),
            ];
            $data = $this->voguepaySubmit($info);
        }
        elseif ($gateway == 'flutterwave') {
            $info = [
                'tx_ref' => $txn,
                'amount' => $txnInfo->pay_amount,
                'currency' => $txnInfo->pay_currency,
                'payment_options' => 'card',
                'redirect_url' => route('gateway.flutterwave.callback'),
                'customer' => [
                    'email' => auth()->user()->email,
                    'name' => auth()->user()->full_name
                ],
                'meta' => [
                    'price' => $txnInfo->pay_amount
                ],
                'customizations' => [
                    'title' => 'Paying for a sample product',
                    'description' => 'sample'
                ]
            ];
            return self::flutterwave($info);
        }
        elseif ($gateway == 'coingate') {


            $client = new \CoinGate\Client('NPfn5eAGjha_PqfQmC6F_rMA6_zaGVLmVk6Uvsfu', true);



            $params = [
                'order_id'          => $txn,
                'price_amount'      => $txnInfo->pay_amount,
                'price_currency'    => $txnInfo->pay_currency,
                'receive_currency'  => 'EUR',
                'callback_url'      => route('gateway.coingate.callback',['user_id' => auth()->user()->id]),
                'cancel_url'        => route('gateway.coingate.cancel'),
                'success_url'       => route('gateway.coingate.success'),
                'title'             => auth()->user()->full_name,
                'description'       => auth()->user()->email
            ];

            $status = $client->order->create($params);

            return redirect($status->payment_url);
        }
        else {
            notify()->success('Successfully Investment Apply', 'success');
            return redirect()->route('user.invest-logs');
        }

        return view($data['view'], compact('txn', 'data'));

    }
}
