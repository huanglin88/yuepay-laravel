<?php

namespace YuePay\V2;

use YuePay\Exceptions\YuePayException;

/**
 * 粤收付 V2.0（开发平台 2.0）支付服务
 *
 * 与 V1.0 的 {@see \YuePay\YuePayService} 并存且完全独立：
 * - V1.0 服务走 /api/... 旧路径（含 reqTime/version/signType，渠道参数 channelExtra）
 * - V2.0 服务走 /api/v2/... 路径（orgNo + orderDate，渠道参数 alipayData/wxpayData/unionpayData）
 *
 * 覆盖接口（均已按官方 2.0 文档核对）：
 * - 微信支付       POST /api/v2/pay/wechat/order
 * - 支付宝支付     POST /api/v2/pay/alipay/order
 * - 银联二维码     POST /api/v2/pay/uniQrcode/order
 * - 收银台预下单   POST /api/v2/pay/common/preOrder
 * - 订单关单       POST /api/v2/pay/close
 * - 查询支付订单   POST /api/v2/pay/query
 * - 统一退款       POST /api/v2/refund/refundOrder
 * - 查询退款订单   POST /api/v2/pay/query（复用查单路径，依请求体退款字段区分）
 * - 请求分账       POST /api/v2/division/orderApply
 * - 查询分账       POST /api/v2/division/orderApplyQuery
 * - 发起分账回退   POST /api/v2/split/splitRefundOrder
 * - 分账回退查询   POST /api/v2/split/splitRefundOrderQuery
 * - 获取渠道用户ID GET  /api/v2/channelUserId/jump
 */
class V2Service
{
    // ---- 支付 ----
    private const URI_WECHAT_ORDER   = '/api/v2/pay/wechat/order';
    private const URI_ALIPAY_ORDER   = '/api/v2/pay/alipay/order';
    private const URI_UNIONPAY_ORDER = '/api/v2/pay/uniQrcode/order';
    private const URI_PRE_ORDER      = '/api/v2/pay/common/preOrder';
    private const URI_CLOSE          = '/api/v2/pay/close';
    private const URI_QUERY          = '/api/v2/pay/query';

    // ---- 退款 ----
    private const URI_REFUND         = '/api/v2/refund/refundOrder';

    // ---- 分账 / 分账回退 ----
    private const URI_DIVISION_APPLY     = '/api/v2/division/orderApply';
    private const URI_DIVISION_QUERY     = '/api/v2/division/orderApplyQuery';
    private const URI_SPLIT_REFUND       = '/api/v2/split/splitRefundOrder';
    private const URI_SPLIT_REFUND_QUERY = '/api/v2/split/splitRefundOrderQuery';

    // ---- 渠道用户ID ----
    private const URI_CHANNEL_USERID_JUMP = '/api/v2/channelUserId/jump';

    /** @var V2Client */
    private $client;

    public function __construct(V2Client $client)
    {
        $this->client = $client;
    }

    // =====================================================================
    // 支付
    // =====================================================================

    /**
     * 统一下单（按 wayCode 自动路由到微信/支付宝/银联对应接口）
     *
     * @param array $params 下单参数：
     *   - mchOrderNo string 商户订单号（必填）
     *   - amount     int    金额，单位分（必填）
     *   - subject    string 商品标题（必填）
     *   - wayCode    string V2.0 支付方式（如 A_JSAPI / T_NATIVE / U_NATIVE），缺省取配置 default_way_code
     *   - body       string 商品描述（缺省用 subject）
     *   - clientIp   string 客户端IP（缺省自动获取）
     *   - notifyUrl  string 异步通知地址（缺省取配置 notify_url）
     *   - extParam   string 商户扩展参数（回调原样返回）
     *   - appid      string 应用 AppID（选填）
     *   - expireTime int    订单过期秒数（≤7200）
     *   - delayedFlag string 延迟入账 0/1
     *   - preOrderType string 拉起类型（T_APP_MINI 微信小程序 / T_EMBED_MINI 微信半屏小程序 / A_APP_MINI 支付宝小程序）
     *   - returnUrl  string 前台跳转地址（支付宝/银联常用）
     *   - 渠道参数：可直接传 alipayData / wxpayData / unionpayData 数组，
     *     或扁平传 authCode / openid / subAppId / buyerUserId / userId / payDataType / frontUrl
     * @return array
     * @throws YuePayException
     */
    public function pay(array $params): array
    {
        if (empty($params['wayCode'])) {
            $params['wayCode'] = $this->client->getConfig()['default_way_code'] ?? '';
        }
        if (empty($params['wayCode'])) {
            throw new YuePayException('缺少必填参数: wayCode');
        }

        switch (WayCode::channelOf($params['wayCode'])) {
            case WayCode::CHANNEL_WECHAT:
                return $this->wechatOrder($params);
            case WayCode::CHANNEL_ALIPAY:
                return $this->alipayOrder($params);
            default:
                return $this->unionpayOrder($params);
        }
    }

    /**
     * 微信支付下单
     */
    public function wechatOrder(array $params): array
    {
        return $this->postOrder(self::URI_WECHAT_ORDER, $params, 'wxpayData');
    }

    /**
     * 支付宝支付下单
     */
    public function alipayOrder(array $params): array
    {
        return $this->postOrder(self::URI_ALIPAY_ORDER, $params, 'alipayData');
    }

    /**
     * 银联二维码支付下单
     */
    public function unionpayOrder(array $params): array
    {
        return $this->postOrder(self::URI_UNIONPAY_ORDER, $params, 'unionpayData');
    }

    /**
     * 收银台预下单（App/小程序拉起微信、支付宝、云闪付）
     *
     * @param array $params 同 pay()，另必填 preOrderType：
     *   T_APP_MINI（微信小程序）/ T_EMBED_MINI（微信半屏小程序）/ A_APP_MINI（支付宝小程序）
     */
    public function preOrder(array $params): array
    {
        if (empty($params['preOrderType'])) {
            throw new YuePayException('缺少必填参数: preOrderType');
        }

        // 收银台预下单无 wayCode；preOrderType 已由 buildCommonPayload 统一透传
        $payload = $this->buildCommonPayload($params);

        return $this->client->post(self::URI_PRE_ORDER, $payload);
    }

    /**
     * 关闭订单
     *
     * @param string $payOrderId  粤收付支付订单号（与 mchOrderNo 二选一）
     * @param string $mchOrderNo  商户订单号（与 payOrderId 二选一）
     * @param string $requestNo   请求唯一标识（不传自动生成）
     */
    public function closeOrder(string $payOrderId = '', string $mchOrderNo = '', string $requestNo = ''): array
    {
        if (empty($payOrderId) && empty($mchOrderNo)) {
            throw new YuePayException('商户订单号和支付订单号不能同时为空');
        }

        $payload = [
            'requestNo' => $requestNo !== '' ? $requestNo : $this->genRequestNo('CLOSE'),
        ];
        if ($payOrderId !== '') {
            $payload['payOrderId'] = $payOrderId;
        }
        if ($mchOrderNo !== '') {
            $payload['mchOrderNo'] = $mchOrderNo;
        }

        return $this->client->post(self::URI_CLOSE, $payload);
    }

    /**
     * 查询支付订单
     *
     * @param string $payOrderId  粤收付支付订单号（与 mchOrderNo 二选一）
     * @param string $mchOrderNo  商户订单号（与 payOrderId 二选一）
     * @param string $requestNo   请求唯一标识（不传自动生成）
     * @param string $orderDate   下单日期 yyyy-MM-dd（不传默认今天）
     */
    public function queryOrder(string $payOrderId = '', string $mchOrderNo = '', string $requestNo = '', string $orderDate = ''): array
    {
        if (empty($payOrderId) && empty($mchOrderNo)) {
            throw new YuePayException('商户订单号和支付订单号不能同时为空');
        }

        $payload = [
            'requestNo' => $requestNo !== '' ? $requestNo : $this->genRequestNo('QUERY'),
        ];
        if ($orderDate !== '') {
            $payload['orderDate'] = $orderDate;
        }
        if ($payOrderId !== '') {
            $payload['payOrderId'] = $payOrderId;
        }
        if ($mchOrderNo !== '') {
            $payload['mchOrderNo'] = $mchOrderNo;
        }

        return $this->client->post(self::URI_QUERY, $payload);
    }

    // =====================================================================
    // 退款
    // =====================================================================

    /**
     * 统一退款
     *
     * @param array $params：
     *   - mchRefundNo  string 商户退款单号（必填，幂等键）
     *   - refundAmount int    退款金额，单位分（必填）
     *   - refundReason string 退款原因（必填）
     *   - orderDate    string 原支付订单的下单日期 yyyy-MM-dd（必填；跨日退款务必传原单日期）
     *   - payOrderId / mchOrderNo 原单号（二选一）
     *   - notifyUrl    string 退款异步通知地址（选填）
     *   - extParam     string 扩展参数（选填）
     */
    public function refund(array $params): array
    {
        if (empty($params['payOrderId']) && empty($params['mchOrderNo'])) {
            throw new YuePayException('原商户订单号和支付订单号不能同时为空');
        }
        if (empty($params['mchRefundNo'])) {
            throw new YuePayException('缺少必填参数: mchRefundNo');
        }
        if (empty($params['refundAmount']) || (int) $params['refundAmount'] <= 0) {
            throw new YuePayException('退款金额必须大于0（单位：分）');
        }
        if (empty($params['refundReason'])) {
            throw new YuePayException('缺少必填参数: refundReason');
        }

        $payload = [
            'mchRefundNo'  => $params['mchRefundNo'],
            'refundAmount' => (int) $params['refundAmount'],
            'refundReason' => $params['refundReason'],
            // 原订单下单日期（V2.0 必填，非今天）
            'orderDate'    => $params['orderDate'] ?? date('Y-m-d'),
        ];

        if (!empty($params['payOrderId'])) {
            $payload['payOrderId'] = $params['payOrderId'];
        }
        if (!empty($params['mchOrderNo'])) {
            $payload['mchOrderNo'] = $params['mchOrderNo'];
        }
        if (!empty($params['notifyUrl'])) {
            $payload['notifyUrl'] = $params['notifyUrl'];
        }
        if (isset($params['extParam'])) {
            $payload['extParam'] = $params['extParam'];
        }

        return $this->client->post(self::URI_REFUND, $payload);
    }

    /**
     * 查询退款订单（V2.0 复用查单路径 /api/v2/pay/query，依退款字段区分）
     *
     * @param string $refundOrderId 粤收付退款单号（与 mchRefundNo 二选一）
     * @param string $mchRefundNo   商户退款单号（与 refundOrderId 二选一）
     * @param string $requestNo     请求唯一标识（不传自动生成）
     * @param string $orderDate     原支付订单下单日期 yyyy-MM-dd（选填）
     */
    public function queryRefund(string $refundOrderId = '', string $mchRefundNo = '', string $requestNo = '', string $orderDate = ''): array
    {
        if (empty($refundOrderId) && empty($mchRefundNo)) {
            throw new YuePayException('商户退款单号和退款单号不能同时为空');
        }

        $payload = [
            'requestNo' => $requestNo !== '' ? $requestNo : $this->genRequestNo('REFUNDQ'),
        ];
        if ($orderDate !== '') {
            $payload['orderDate'] = $orderDate;
        }
        if ($refundOrderId !== '') {
            $payload['refundOrderId'] = $refundOrderId;
        }
        if ($mchRefundNo !== '') {
            $payload['mchRefundNo'] = $mchRefundNo;
        }

        return $this->client->post(self::URI_QUERY, $payload);
    }

    // =====================================================================
    // 分账 / 分账回退
    // =====================================================================

    /**
     * 请求分账（订单分账，商户手动发起）
     *
     * @param array $params：
     *   - channelBatchNo     string 分账批次号（必填）
     *   - transSumAmt        int    分账总金额，单位分（必填）
     *   - transSumCount      int    分账总笔数（必填）
     *   - separateOrderDetail array 分账子单明细（必填），每项：receiverId、sepaTransAmount
     *   - notifyUrl          string 异步通知地址（必填，缺省取配置 notify_url）
     *   - payOrderId / mchOrderNo   原支付单号（二选一）
     *   - orderDate          string 下单日期 yyyy-MM-dd（选填）
     */
    public function divisionApply(array $params): array
    {
        $this->requireFields($params, ['channelBatchNo', 'transSumAmt', 'transSumCount']);
        if (empty($params['separateOrderDetail']) || !is_array($params['separateOrderDetail'])) {
            throw new YuePayException('缺少必填参数: separateOrderDetail（分账子单明细数组）');
        }
        if (empty($params['payOrderId']) && empty($params['mchOrderNo'])) {
            throw new YuePayException('支付订单号和商户订单号不能同时为空');
        }

        $config = $this->client->getConfig();

        $payload = [
            'channelBatchNo'      => $params['channelBatchNo'],
            'transSumAmt'         => (int) $params['transSumAmt'],
            'transSumCount'       => (int) $params['transSumCount'],
            'notifyUrl'           => $params['notifyUrl'] ?? ($config['notify_url'] ?? ''),
            'separateOrderDetail' => $params['separateOrderDetail'],
        ];

        if (!empty($params['payOrderId'])) {
            $payload['payOrderId'] = $params['payOrderId'];
        }
        if (!empty($params['mchOrderNo'])) {
            $payload['mchOrderNo'] = $params['mchOrderNo'];
        }
        if (!empty($params['orderDate'])) {
            $payload['orderDate'] = $params['orderDate'];
        }

        return $this->client->post(self::URI_DIVISION_APPLY, $payload);
    }

    /**
     * 查询分账
     *
     * @param array $params：
     *   - channelBatchNo string 分账批次号（必填）
     *   - requestNo      string 请求唯一标识（选填，自动生成）
     *   - payOrderId / mchOrderNo 原支付单号（二选一）
     *   - receiverId     string 分账收款人ID（选填，过滤）
     *   - orderDate      string 下单日期 yyyy-MM-dd（选填）
     */
    public function divisionQuery(array $params): array
    {
        $this->requireFields($params, ['channelBatchNo']);

        $payload = [
            'requestNo'      => $params['requestNo'] ?? $this->genRequestNo('DIVQ'),
            'channelBatchNo' => $params['channelBatchNo'],
        ];

        if (!empty($params['payOrderId'])) {
            $payload['payOrderId'] = $params['payOrderId'];
        }
        if (!empty($params['mchOrderNo'])) {
            $payload['mchOrderNo'] = $params['mchOrderNo'];
        }
        if (!empty($params['receiverId'])) {
            $payload['receiverId'] = $params['receiverId'];
        }
        if (!empty($params['orderDate'])) {
            $payload['orderDate'] = $params['orderDate'];
        }

        return $this->client->post(self::URI_DIVISION_QUERY, $payload);
    }

    /**
     * 发起分账回退
     *
     * @param array $params：
     *   - channelBatchNo      string 原分账批次号（必填）
     *   - splitRefundBatchNo  string 分账回退批次号（必填）
     *   - refundAmountSum     int    回退总金额，单位分（必填）
     *   - separateRefundList  array  回退明细（必填），每项：receiverId、refundAmount
     *   - orderDate           string 原分账请求日期 yyyy-MM-dd（必填）
     *   - payOrderId / mchOrderNo    原支付单号（二选一）
     */
    public function splitRefund(array $params): array
    {
        $this->requireFields($params, ['channelBatchNo', 'splitRefundBatchNo', 'refundAmountSum', 'orderDate']);
        if (empty($params['separateRefundList']) || !is_array($params['separateRefundList'])) {
            throw new YuePayException('缺少必填参数: separateRefundList（分账回退明细数组）');
        }
        if (empty($params['payOrderId']) && empty($params['mchOrderNo'])) {
            throw new YuePayException('支付订单号和商户订单号不能同时为空');
        }

        $payload = [
            'channelBatchNo'     => $params['channelBatchNo'],
            'splitRefundBatchNo' => $params['splitRefundBatchNo'],
            'refundAmountSum'    => (int) $params['refundAmountSum'],
            'separateRefundList' => $params['separateRefundList'],
            'orderDate'          => $params['orderDate'],
        ];

        if (!empty($params['payOrderId'])) {
            $payload['payOrderId'] = $params['payOrderId'];
        }
        if (!empty($params['mchOrderNo'])) {
            $payload['mchOrderNo'] = $params['mchOrderNo'];
        }

        return $this->client->post(self::URI_SPLIT_REFUND, $payload);
    }

    /**
     * 分账回退查询
     *
     * @param array $params：
     *   - channelBatchNo string 分账批次号（必填）
     *   - requestNo      string 请求唯一标识（选填，自动生成）
     *   - payOrderId / mchOrderNo 原支付单号（二选一）
     *   - receiverId     string 分账收款人ID（选填）
     *   - orderDate      string 申请回退日期 yyyy-MM-dd（选填）
     */
    public function splitRefundQuery(array $params): array
    {
        $this->requireFields($params, ['channelBatchNo']);

        $payload = [
            'requestNo'      => $params['requestNo'] ?? $this->genRequestNo('SPLITQ'),
            'channelBatchNo' => $params['channelBatchNo'],
        ];

        if (!empty($params['payOrderId'])) {
            $payload['payOrderId'] = $params['payOrderId'];
        }
        if (!empty($params['mchOrderNo'])) {
            $payload['mchOrderNo'] = $params['mchOrderNo'];
        }
        if (!empty($params['receiverId'])) {
            $payload['receiverId'] = $params['receiverId'];
        }
        if (!empty($params['orderDate'])) {
            $payload['orderDate'] = $params['orderDate'];
        }

        return $this->client->post(self::URI_SPLIT_REFUND_QUERY, $payload);
    }

    // =====================================================================
    // 渠道用户ID
    // =====================================================================

    /**
     * 获取渠道用户ID跳转链接（GET，直接 302 跳转即可）
     *
     * @param array $params：
     *   - ifCode      string 支付接口，目前仅支持 AUTO（必填）
     *   - redirectUrl string 授权完成后的回调地址（必填）
     *   - appId       string 粤收付应用 APPID（选填，注意此接口字段为 appId 驼峰）
     *   - extParam    string 扩展参数（选填，原样返回）
     * @return string 完整跳转 URL
     */
    public function channelUserIdUrl(array $params): string
    {
        $this->requireFields($params, ['ifCode', 'redirectUrl']);

        $payload = [
            'ifCode'      => $params['ifCode'],
            'redirectUrl' => $params['redirectUrl'],
        ];
        if (!empty($params['appId'])) {
            $payload['appId'] = $params['appId'];
        }
        if (!empty($params['extParam'])) {
            $payload['extParam'] = $params['extParam'];
        }

        return $this->client->buildUrl(self::URI_CHANNEL_USERID_JUMP, $payload);
    }

    // =====================================================================
    // 内部方法
    // =====================================================================

    /**
     * 统一下单公共流程
     */
    private function postOrder(string $uri, array $params, string $dataKey): array
    {
        if (empty($params['wayCode'])) {
            throw new YuePayException('缺少必填参数: wayCode');
        }

        $payload = $this->buildCommonPayload($params);
        $payload['wayCode'] = $params['wayCode'];

        $channelData = $this->buildChannelData($params, $dataKey);
        if (!empty($channelData)) {
            $payload[$dataKey] = $channelData;
        }

        return $this->client->post($uri, $payload);
    }

    /**
     * 构建下单公共字段（不含 wayCode 与渠道数据对象）
     */
    private function buildCommonPayload(array $params): array
    {
        $config = $this->client->getConfig();
        $this->validateOrderParams($params);

        $payload = [
            'mchOrderNo' => $params['mchOrderNo'],
            'amount'     => (int) $params['amount'],
            'currency'   => $params['currency'] ?? ($config['currency'] ?? 'CNY'),
            'subject'    => $params['subject'],
            'body'       => $params['body'] ?? $params['subject'],
            'clientIp'   => $params['clientIp'] ?? $this->getClientIp(),
            'notifyUrl'  => $params['notifyUrl'] ?? ($config['notify_url'] ?? ''),
            'extParam'   => $params['extParam'] ?? '',
        ];

        // 应用 AppID 字段名可配置（schema: appid / 示例页: appId）
        if (!empty($params['appid'])) {
            $payload[$config['appid_field'] ?? 'appid'] = $params['appid'];
        }

        // 订单过期时间字段名可配置（schema: expireTime / 示例页: expiredTime）
        if (isset($params['expireTime']) && $params['expireTime'] !== '') {
            $payload[$config['expire_time_field'] ?? 'expireTime'] = (int) $params['expireTime'];
        }

        if (isset($params['delayedFlag']) && $params['delayedFlag'] !== '') {
            $payload['delayedFlag'] = (string) $params['delayedFlag'];
        }

        // preOrderType：与 V1.0 统一下单保持一致，传了就透传
        // 用于 App/小程序拉起场景：T_APP_MINI(微信小程序) / T_EMBED_MINI(微信半屏小程序) / A_APP_MINI(支付宝小程序)
        if (isset($params['preOrderType'])) {
            $payload['preOrderType'] = $params['preOrderType'];
        }

        // returnUrl 未列入官方下单 schema，但签名示例页出现，按需透传
        if (!empty($params['returnUrl'])) {
            $payload['returnUrl'] = $params['returnUrl'];
        }

        return $payload;
    }

    /**
     * 构建渠道数据对象（alipayData / wxpayData / unionpayData）
     *
     * 支持两种写法：
     * 1) 直接传嵌套数组：['alipayData' => ['buyerUserId' => 'xxx']]
     * 2) 扁平传参：['buyerUserId' => 'xxx']，自动收进对应对象
     */
    private function buildChannelData(array $params, string $dataKey): array
    {
        $allowed = [
            'alipayData'   => ['authCode', 'payDataType', 'buyerUserId'],
            'wxpayData'    => ['authCode', 'openid', 'subAppId', 'payDataType'],
            'unionpayData' => ['frontUrl', 'payDataType', 'userId', 'authCode'],
        ];
        $allowedFields = $allowed[$dataKey] ?? null;

        $data = [];
        if (!empty($params[$dataKey]) && is_array($params[$dataKey])) {
            $data = $params[$dataKey];
        }

        // 扁平参数兜底
        if ($allowedFields !== null) {
            foreach ($allowedFields as $field) {
                if (!isset($data[$field]) && isset($params[$field]) && $params[$field] !== '') {
                    $data[$field] = $params[$field];
                }
            }
            // 只保留该渠道合法字段，避免把其他渠道字段塞进来影响签名
            $data = array_intersect_key($data, array_flip($allowedFields));
        }

        return array_filter($data, function ($value) {
            return $value !== null && $value !== '';
        });
    }

    /**
     * 校验下单基础参数
     */
    private function validateOrderParams(array $params): void
    {
        $this->requireFields($params, ['mchOrderNo', 'amount', 'subject']);

        if (!is_numeric($params['amount']) || (int) $params['amount'] <= 0) {
            throw new YuePayException('支付金额必须为大于0的整数（单位：分）');
        }
    }

    /**
     * 校验必填字段
     */
    private function requireFields(array $params, array $fields): void
    {
        foreach ($fields as $field) {
            if (!isset($params[$field]) || $params[$field] === '') {
                throw new YuePayException("缺少必填参数: {$field}");
            }
        }
    }

    /**
     * 生成请求唯一标识
     */
    private function genRequestNo(string $prefix): string
    {
        return $prefix . date('YmdHis') . str_pad((string) mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * 获取客户端IP
     */
    private function getClientIp(): string
    {
        if (app()->runningInConsole()) {
            return '127.0.0.1';
        }

        $ip = request()->ip();

        return $ip ?: '127.0.0.1';
    }
}
