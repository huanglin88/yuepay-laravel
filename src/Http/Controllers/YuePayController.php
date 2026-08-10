<?php

namespace YuePay\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use YuePay\Callback\CallbackHandler;
use YuePay\YuePayService;
use YuePay\Exceptions\YuePayException;

/**
 * 粤收付支付示例控制器
 *
 * 演示完整支付流程：下单 → 支付 → 回调 → 查询 → 退款
 */
class YuePayController extends Controller
{
    public function __construct(
        private YuePayService $yuePay,
        private CallbackHandler $callback,
    ) {}

    /**
     * 创建支付订单（统一下单）
     *
     * POST /api/yuepay/order
     */
    public function createOrder(Request $request)
    {
        $validated = $request->validate([
            'mchOrderNo' => 'required|string',
            'amount'     => 'required|integer|min:1',
            'subject'    => 'required|string|max:128',
            'body'       => 'nullable|string|max:256',
            'wayCode'    => 'nullable|string',
            'openid'     => 'nullable|string',    // JSAPI支付需要
            'authCode'   => 'nullable|string',    // 条码支付需要
            'returnUrl'  => 'nullable|string',
            'notifyUrl'  => 'nullable|string',
        ]);

        try {
            $result = $this->yuePay->unifiedOrder($validated);

            // result 中通常包含 payData（支付凭证，如微信预支付ID、支付宝跳转链接等）
            return response()->json([
                'code' => 0,
                'msg'  => '下单成功',
                'data' => $result['data'] ?? $result,
            ]);
        } catch (YuePayException $e) {
            Log::error('粤收付下单失败', [
                'order'   => $validated,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'code' => -1,
                'msg'  => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 查询支付订单
     *
     * GET /api/yuepay/query?mchOrderNo=xxx
     */
    public function queryOrder(Request $request)
    {
        $request->validate([
            'mchOrderNo'  => 'nullable|string',
            'payOrderId'  => 'nullable|string',
        ]);

        try {
            $result = $this->yuePay->queryOrder(
                $request->input('mchOrderNo', ''),
                $request->input('payOrderId'),
            );

            return response()->json([
                'code' => 0,
                'msg'  => '查询成功',
                'data' => $result['data'] ?? $result,
            ]);
        } catch (YuePayException $e) {
            return response()->json([
                'code' => -1,
                'msg'  => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 关闭订单
     *
     * POST /api/yuepay/close
     */
    public function closeOrder(Request $request)
    {
        $request->validate([
            'mchOrderNo' => 'nullable|string',
            'payOrderId' => 'nullable|string',
        ]);

        try {
            $result = $this->yuePay->closeOrder(
                $request->input('mchOrderNo', ''),
                $request->input('payOrderId'),
            );

            return response()->json([
                'code' => 0,
                'msg'  => '关闭成功',
                'data' => $result['data'] ?? $result,
            ]);
        } catch (YuePayException $e) {
            return response()->json([
                'code' => -1,
                'msg'  => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 申请退款
     *
     * POST /api/yuepay/refund
     */
    public function refund(Request $request)
    {
        $validated = $request->validate([
            'mchOrderNo'    => 'nullable|string',
            'payOrderId'    => 'nullable|string',
            'mchRefundNo'   => 'required|string',
            'refundAmount'  => 'required|integer|min:1',
            'refundReason'  => 'nullable|string|max:128',
            'notifyUrl'     => 'nullable|string',
        ]);

        try {
            $result = $this->yuePay->refund($validated);

            return response()->json([
                'code' => 0,
                'msg'  => '退款申请成功',
                'data' => $result['data'] ?? $result,
            ]);
        } catch (YuePayException $e) {
            Log::error('粤收付退款失败', [
                'refund' => $validated,
                'error'  => $e->getMessage(),
            ]);

            return response()->json([
                'code' => -1,
                'msg'  => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 查询退款订单
     *
     * GET /api/yuepay/refund/query?mchRefundNo=xxx
     */
    public function queryRefund(Request $request)
    {
        $request->validate([
            'mchRefundNo'   => 'nullable|string',
            'refundOrderId' => 'nullable|string',
        ]);

        try {
            $result = $this->yuePay->queryRefund(
                $request->input('mchRefundNo', ''),
                $request->input('refundOrderId'),
            );

            return response()->json([
                'code' => 0,
                'msg'  => '查询成功',
                'data' => $result['data'] ?? $result,
            ]);
        } catch (YuePayException $e) {
            return response()->json([
                'code' => -1,
                'msg'  => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 支付结果异步回调通知
     *
     * POST /api/yuepay/notify
     *
     * 粤收付会在支付成功后向此地址发送异步通知
     * 验签通过后处理业务逻辑，返回 "success"
     */
    public function notify(Request $request)
    {
        Log::info('粤收付支付回调收到通知', $request->all());

        try {
            // 验签并获取数据
            $data = $this->callback->handle($request);

            // 解析支付回调
            $notifyData = $this->callback->parsePaymentNotify($data);

            // 根据回调类型区分支付/退款
            $backType = $notifyData['backType'] ?? 'PAY';

            if ($backType === 'REFUND') {
                return $this->handleRefundNotify($notifyData);
            }

            return $this->handlePaymentNotify($notifyData);

        } catch (YuePayException $e) {
            Log::error('粤收付回调处理失败', [
                'error' => $e->getMessage(),
                'data'  => $request->all(),
            ]);

            return response('fail', 500);
        }
    }

    /**
     * 处理支付回调
     */
    private function handlePaymentNotify(array $notifyData)
    {
        // state=2 表示支付成功
        if ($notifyData['state'] == 2) {
            // TODO: 在这里处理你的业务逻辑
            // 1. 根据商户订单号查找本地订单
            // 2. 校验金额是否一致
            // 3. 更新订单状态为已支付
            // 4. 发放权益/通知下游等
            // 5. 注意幂等处理（同一订单可能收到多次回调）

            Log::info('支付成功', [
                'mchOrderNo' => $notifyData['mchOrderNo'],
                'amount'     => $notifyData['amount'],
                'payOrderId' => $notifyData['payOrderId'],
            ]);

            // 示例：更新本地订单
            // Order::where('order_no', $notifyData['mchOrderNo'])
            //     ->where('amount', $notifyData['amount'])
            //     ->update([
            //         'status'       => 'paid',
            //         'paid_at'      => now(),
            //         'pay_order_id' => $notifyData['payOrderId'],
            //     ]);
        }

        return response($this->callback->successResponse());
    }

    /**
     * 处理退款回调
     */
    private function handleRefundNotify(array $notifyData)
    {
        // TODO: 处理退款回调业务逻辑
        // 1. 根据 mchRefundNo 查找本地退款单
        // 2. 更新退款状态
        // 3. 注意幂等处理

        Log::info('退款回调', $notifyData);

        return response($this->callback->successResponse());
    }
}
