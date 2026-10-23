<?php

namespace YuePay\V2\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use YuePay\Exceptions\YuePayException;
use YuePay\V2\V2CallbackHandler;
use YuePay\V2\V2Service;

/**
 * 粤收付 V2.0 示例控制器
 *
 * 演示 V2.0 完整流程：下单 → 支付 → 回调 → 查询 → 退款 → 分账
 * 路由前缀为 yuepay/v2，与 V1.0 的 yuepay 前缀互不冲突。
 *
 * 说明：本控制器仅作接入示例，业务项目可按需复制或自行封装。
 */
class V2Controller extends Controller
{
    /** @var V2Service */
    private $yuePayV2;

    /** @var V2CallbackHandler */
    private $callback;

    public function __construct(V2Service $yuePayV2, V2CallbackHandler $callback)
    {
        $this->yuePayV2 = $yuePayV2;
        $this->callback = $callback;
    }

    /**
     * 创建支付订单（V2.0 统一下单，按 wayCode 自动路由渠道）
     *
     * POST /api/yuepay/v2/order
     */
    public function createOrder(Request $request)
    {
        $validated = $request->validate([
            'mchOrderNo'   => 'required|string',
            'amount'       => 'required|integer|min:1',
            'subject'      => 'required|string|max:128',
            'wayCode'      => 'nullable|string',        // V2.0：A_* / T_* / U_*
            'body'         => 'nullable|string|max:256',
            'clientIp'     => 'nullable|string',
            'notifyUrl'    => 'nullable|string',
            'returnUrl'    => 'nullable|string',
            'extParam'     => 'nullable|string',
            'appid'        => 'nullable|string',
            'expireTime'   => 'nullable|integer|max:7200',
            'delayedFlag'  => 'nullable|string|in:0,1',
            'preOrderType' => 'nullable|string|in:T_APP_MINI,T_EMBED_MINI,A_APP_MINI',
            // 渠道扁平参数
            'authCode'     => 'nullable|string',
            'openid'       => 'nullable|string',
            'subAppId'     => 'nullable|string',
            'buyerUserId'  => 'nullable|string',
            'userId'       => 'nullable|string',
            'payDataType'  => 'nullable|string',
            'frontUrl'     => 'nullable|string',
            // 渠道嵌套对象（与扁平参数二选一）
            'alipayData'   => 'nullable|array',
            'wxpayData'    => 'nullable|array',
            'unionpayData' => 'nullable|array',
        ]);

        return $this->respond(function () use ($validated) {
            return $this->yuePayV2->pay($validated);
        }, $validated, '下单成功');
    }

    /**
     * 收银台预下单
     *
     * POST /api/yuepay/v2/pre-order
     */
    public function preOrder(Request $request)
    {
        $validated = $request->validate([
            'mchOrderNo'   => 'required|string',
            'amount'       => 'required|integer|min:1',
            'subject'      => 'required|string|max:128',
            'preOrderType' => 'required|string|in:T_APP_MINI,T_EMBED_MINI,A_APP_MINI',
            'body'         => 'nullable|string|max:256',
            'notifyUrl'    => 'nullable|string',
            'returnUrl'    => 'nullable|string',
            'appid'        => 'nullable|string',
        ]);

        return $this->respond(function () use ($validated) {
            return $this->yuePayV2->preOrder($validated);
        }, $validated, '预下单成功');
    }

    /**
     * 查询支付订单
     *
     * GET /api/yuepay/v2/query?mchOrderNo=xxx
     */
    public function queryOrder(Request $request)
    {
        $request->validate([
            'mchOrderNo' => 'nullable|string',
            'payOrderId' => 'nullable|string',
            'requestNo'  => 'nullable|string',
            'orderDate'  => 'nullable|string',
        ]);

        return $this->respond(function () use ($request) {
            return $this->yuePayV2->queryOrder(
                (string) $request->input('payOrderId', ''),
                (string) $request->input('mchOrderNo', ''),
                (string) $request->input('requestNo', ''),
                (string) $request->input('orderDate', '')
            );
        }, [], '查询成功');
    }

    /**
     * 关闭订单
     *
     * POST /api/yuepay/v2/close
     */
    public function closeOrder(Request $request)
    {
        $request->validate([
            'mchOrderNo' => 'nullable|string',
            'payOrderId' => 'nullable|string',
            'requestNo'  => 'nullable|string',
        ]);

        return $this->respond(function () use ($request) {
            return $this->yuePayV2->closeOrder(
                (string) $request->input('payOrderId', ''),
                (string) $request->input('mchOrderNo', ''),
                (string) $request->input('requestNo', '')
            );
        }, [], '关闭成功');
    }

    /**
     * 统一退款
     *
     * POST /api/yuepay/v2/refund
     */
    public function refund(Request $request)
    {
        $validated = $request->validate([
            'mchOrderNo'   => 'nullable|string',
            'payOrderId'   => 'nullable|string',
            'mchRefundNo'  => 'required|string',
            'refundAmount' => 'required|integer|min:1',
            'refundReason' => 'required|string|max:128',
            'orderDate'    => 'nullable|string',   // 原订单下单日期，跨日退款务必传
            'notifyUrl'    => 'nullable|string',
            'extParam'     => 'nullable|string',
        ]);

        return $this->respond(function () use ($validated) {
            return $this->yuePayV2->refund($validated);
        }, $validated, '退款申请成功');
    }

    /**
     * 查询退款订单
     *
     * GET /api/yuepay/v2/refund/query?mchRefundNo=xxx
     */
    public function queryRefund(Request $request)
    {
        $request->validate([
            'mchRefundNo'   => 'nullable|string',
            'refundOrderId' => 'nullable|string',
            'requestNo'     => 'nullable|string',
            'orderDate'     => 'nullable|string',
        ]);

        return $this->respond(function () use ($request) {
            return $this->yuePayV2->queryRefund(
                (string) $request->input('refundOrderId', ''),
                (string) $request->input('mchRefundNo', ''),
                (string) $request->input('requestNo', ''),
                (string) $request->input('orderDate', '')
            );
        }, [], '查询成功');
    }

    /**
     * 请求分账
     *
     * POST /api/yuepay/v2/division/apply
     */
    public function divisionApply(Request $request)
    {
        $validated = $request->validate([
            'channelBatchNo'         => 'required|string',
            'transSumAmt'            => 'required|integer|min:1',
            'transSumCount'          => 'required|integer|min:1',
            'separateOrderDetail'    => 'required|array|min:1',
            'notifyUrl'              => 'nullable|string',
            'payOrderId'             => 'nullable|string',
            'mchOrderNo'             => 'nullable|string',
            'orderDate'              => 'nullable|string',
        ]);

        return $this->respond(function () use ($validated) {
            return $this->yuePayV2->divisionApply($validated);
        }, $validated, '分账请求已受理');
    }

    /**
     * 查询分账
     *
     * POST /api/yuepay/v2/division/query
     */
    public function divisionQuery(Request $request)
    {
        $validated = $request->validate([
            'channelBatchNo' => 'required|string',
            'requestNo'      => 'nullable|string',
            'payOrderId'     => 'nullable|string',
            'mchOrderNo'     => 'nullable|string',
            'receiverId'     => 'nullable|string',
            'orderDate'      => 'nullable|string',
        ]);

        return $this->respond(function () use ($validated) {
            return $this->yuePayV2->divisionQuery($validated);
        }, $validated, '查询成功');
    }

    /**
     * 发起分账回退
     *
     * POST /api/yuepay/v2/split/refund
     */
    public function splitRefund(Request $request)
    {
        $validated = $request->validate([
            'channelBatchNo'     => 'required|string',
            'splitRefundBatchNo' => 'required|string',
            'refundAmountSum'    => 'required|integer|min:1',
            'separateRefundList' => 'required|array|min:1',
            'orderDate'          => 'required|string',
            'payOrderId'         => 'nullable|string',
            'mchOrderNo'         => 'nullable|string',
        ]);

        return $this->respond(function () use ($validated) {
            return $this->yuePayV2->splitRefund($validated);
        }, $validated, '分账回退请求已受理');
    }

    /**
     * 获取渠道用户ID跳转链接
     *
     * GET /api/yuepay/v2/channel-user-id/url?ifCode=AUTO&redirectUrl=xxx
     */
    public function channelUserIdUrl(Request $request)
    {
        $validated = $request->validate([
            'ifCode'      => 'required|string',
            'redirectUrl' => 'required|string',
            'appId'       => 'nullable|string',
            'extParam'    => 'nullable|string',
        ]);

        try {
            $url = $this->yuePayV2->channelUserIdUrl($validated);

            return response()->json([
                'code' => 0,
                'msg'  => '获取成功',
                'data' => ['url' => $url],
            ]);
        } catch (YuePayException $e) {
            return response()->json([
                'code' => -1,
                'msg'  => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * V2.0 异步回调通知
     *
     * POST /api/yuepay/v2/notify
     */
    public function notify(Request $request)
    {
        Log::info('粤收付 V2 回调收到通知', $request->all());

        try {
            $data = $this->callback->handle($request);

            // TODO: 在此处理业务逻辑（建议按实际报文核对字段，并做幂等处理）
            // 1. 根据 mchOrderNo / mchRefundNo 查找本地单据
            // 2. 校验金额一致
            // 3. 更新状态（幂等）
            // 4. 返回 success

            return response($this->callback->successResponse());
        } catch (YuePayException $e) {
            Log::error('粤收付 V2 回调处理失败', [
                'error' => $e->getMessage(),
                'data'  => $request->all(),
            ]);

            return response($this->callback->failResponse(), 500);
        }
    }

    /**
     * 统一响应包装
     *
     * @param callable $action 实际调用
     * @param array    $context 日志上下文
     * @param string   $successMsg 成功提示
     */
    private function respond(callable $action, array $context, string $successMsg)
    {
        try {
            $result = $action();

            return response()->json([
                'code' => 0,
                'msg'  => $successMsg,
                'data' => $result['data'] ?? $result,
            ]);
        } catch (YuePayException $e) {
            Log::error('粤收付 V2 接口调用失败', [
                'context' => $context,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'code' => -1,
                'msg'  => $e->getMessage(),
            ], 500);
        }
    }
}
