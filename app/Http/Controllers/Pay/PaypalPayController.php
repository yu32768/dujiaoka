<?php

namespace App\Http\Controllers\Pay;


use AmrShawky\LaravelCurrency\Facade\Currency;
use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PayPal\Api\Amount;
use PayPal\Api\Details;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Transaction;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Exception\PayPalConnectionException;
use PayPal\Rest\ApiContext;

class PaypalPayController extends PayController
{

    const Currency = 'USD'; //货币单位

    public function getFrankfurterRate($from, $to)
    {
        $url = "https://api.frankfurter.app/latest?from={$from}&to={$to}";
        $response = file_get_contents($url);
        $data = json_decode($response, true);
        if (isset($data['rates'][$to])) {
            return $data['rates'][$to];
        }
        return null;
    }

    public function gateway(string $payway, string $orderSN)
    {
        try {
            // 加载网关
            $this->loadGateWay($orderSN, $payway);
            $paypal = new ApiContext(
                new OAuthTokenCredential(
                    $this->payGateway->merchant_key,
                    $this->payGateway->merchant_pem
                )
            );
            $paypal->setConfig(['mode' => 'live']);
            $product = $this->order->title;
            // 得到汇率
            $rate = $this->getFrankfurterRate('CNY', 'USD');
            $total = null;
            if ($rate) {
                $total = round($this->order->actual_price * $rate, 2);
                Log::info("paypal支付转换汇率", [
                    'order_sn' => $this->order->order_sn,
                    'original_price_cny' => $this->order->actual_price,
                    'converted_price_usd' => $total,
                    'exchange_rate' => $rate,
                ]);
            }
            // 如果汇率换算失败，直接用 7.0 计算
            if (empty($total)) {
                $total = round($this->order->actual_price / 7.0, 2);
                Log::warning("paypal支付汇率转换失败，使用默认汇率7.0计算", [
                    'order_sn' => $this->order->order_sn,
                    'original_price_cny' => $this->order->actual_price,
                    'converted_price_usd' => $total,
                ]);
            }
            $shipping = 0;
            $description = $this->order->title;
            $payer = new Payer();
            $payer->setPaymentMethod('paypal');
            $item = new Item();
            $item->setName($product)->setCurrency(self::Currency)->setQuantity(1)->setPrice($total);
            $itemList = new ItemList();
            $itemList->setItems([$item]);
            $details = new Details();
            $details->setShipping($shipping)->setSubtotal($total);
            $amount = new Amount();
            $amount->setCurrency(self::Currency)->setTotal($total)->setDetails($details);
            $transaction = new Transaction();
            $transaction->setAmount($amount)->setItemList($itemList)->setDescription($description)->setInvoiceNumber($this->order->order_sn);
            $redirectUrls = new RedirectUrls();
            $redirectUrls->setReturnUrl(route('paypal-return', ['success' => 'ok', 'orderSN' => $this->order->order_sn]))->setCancelUrl(route('paypal-return', ['success' => 'no', 'orderSN' => $this->order->order_sn]));
            $payment = new Payment();
            $payment->setIntent('sale')->setPayer($payer)->setRedirectUrls($redirectUrls)->setTransactions([$transaction]);
            $payment->create($paypal);
            $approvalUrl = $payment->getApprovalLink();
            return redirect($approvalUrl);
        } catch (PayPalConnectionException $payPalConnectionException) {
            // 1. 获取详细的 JSON 格式错误信息字符
            $errorData = $payPalConnectionException->getData();
            // 2. 尝试转换为数组以便 Log 记录（可选，视你的日志驱动而定）
            $errorDetails = json_decode($errorData, true);
            Log::error("paypal支付连接异常", [
                'http_code' => $payPalConnectionException->getCode(), // HTTP状态码 (如 400)
                'summary'   => $payPalConnectionException->getMessage(), // 简要信息
                'details'   => $errorDetails ? $errorDetails : $errorData, // 详细报错内容
                'price'     => $this->order->actual_price,
            ]);

            return $this->err($payPalConnectionException->getMessage());
        } catch (RuleValidationException $exception) {
            return $this->err($exception->getMessage());
        }
    }

    /**
     *paypal 同步回调
     */
    public function returnUrl(Request $request)
    {
        $success = $request->input('success');
        $paymentId =  $request->input('paymentId');
        $payerID =  $request->input('PayerID');
        $orderSN = $request->input('orderSN');
        if ($success == 'no' || empty($paymentId) || empty($payerID)) {
            // 取消支付
            Log::info("paypal支付取消",  ['支付取消，支付ID【' . $paymentId . '】,支付人ID【' . $payerID . '】']);
            return redirect(url('/', ['orderSN' => $payerID]));
        }
        $order = $this->orderService->detailOrderSN($orderSN);
        if (!$order) {
            return 'error';
        }
        $payGateway = $this->payService->detail($order->pay_id);
        if (!$payGateway) {
            return 'error';
        }
        if($payGateway->pay_handleroute != '/pay/paypal'){
            return 'error';
        }
        $paypal = new ApiContext(
            new OAuthTokenCredential(
                $payGateway->merchant_key,
                $payGateway->merchant_pem
            )
        );
        $paypal->setConfig(['mode' => 'live']);
        $payment = Payment::get($paymentId, $paypal);
        $execute = new PaymentExecution();
        $execute->setPayerId($payerID);
        try {
            $payment->execute($execute, $paypal);
            $this->orderProcessService->completedOrder($orderSN, $order->actual_price, $paymentId);
            Log::info("paypal支付成功",  ['支付成功，支付ID【' . $paymentId . '】,支付人ID【' . $payerID . '】']);
        } catch (\Exception $e) {
            Log::error("paypal支付失败", ['支付失败，支付ID【' . $paymentId . '】,支付人ID【' . $payerID . '】']);
        }
        return redirect(url('detail-order-sn', ['orderSN' => $orderSN]));
    }


    /**
     * 异步通知
     * TODO: 暂未实现，但是好像只实现同步回调即可。这个可以放在后面实现
     */
    public function notifyUrl(Request $request)
    {
        //获取回调结果
        $json_data = $this->get_JsonData();
        if(!empty($json_data)){
            Log::debug("paypal notify info:\r\n" . json_encode($json_data));
        }else{
            Log::debug("paypal notify fail:参加为空");
        }

    }

    private function get_JsonData()
    {
        $json = file_get_contents('php://input');
        if ($json) {
            $json = str_replace("'", '', $json);
            $json = json_decode($json,true);
        }
        return $json;
    }

}
