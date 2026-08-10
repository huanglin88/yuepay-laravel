<?php

namespace YuePay;

use YuePay\Exceptions\YuePayException;
use YuePay\Support\HttpClient;

/**
 * 粤收付支付服务
 *
 * 封装粤收付开放平台全部接口：
 * - 统一下单（支持支付宝/微信多种支付方式）
 * - 支付订单查询
 * - 关闭订单
 * - 退款申请
 * - 退款查询
 *
 * 支付方式 wayCode 对照表：
 * - ALI_JSAPI  支付宝JSAPI（小程序/生活号）
 * - ALI_QR     支付宝二维码（扫码支付）
 * - ALI_BAR    支付宝条码支付（付款码）
 * - ALI_WAP    支付宝H5支付
 * - ALI_APP    支付宝APP支付
 * - WX_JSAPI   微信JSAPI（小程序/公众号）
 * - WX_NATIVE  微信扫码支付
 * - WX_BAR     微信条码支付（付款码）
 * - WX_H5      微信H5支付
 * - WX_APP     微信APP支付
 * - QQ_JSAPI   QQ钱包JSAPI
 * - QQ_QR      QQ钱包扫码
 */
class YuePayService
{
    // 接口路径
    private const URI_UNIFIED_ORDER = '/api/pay/unifiedOrder';
    private const URI_QUERY_ORDER   = '/api/pay/query';
    private const URI_CLOSE_ORDER   = '/api/pay/close';
    private const URI_REFUND        = '/api/refund/refundOrder';
    private const URI_QUERY_REFUND  = '/api/refund/query';

    /** @var HttpClient */
    private $client;

    public function __construct(HttpClient $client)
    {
        $this->client = $client;
    }

    /**
     * 统一下单
     *
     * @param array $params 下单参数，必须包含以下字段：
     *   - mchOrderNo  string  商户订单号
     *   - wayCode     string  支付方式（如 ALI_JSAPI, WX_JSAPI）
     *   - amount      int     支付金额，单位：分
     *   - subject     string  商品标题
     * 可选参数：
     *   - body        string  商品描述
     *   - clientIp    string  客户端IP
     *   - notifyUrl   string  异步通知地址
     *   - returnUrl   string  前端跳转地址
     *   - channelExtra string 渠道扩展参数（JSON字符串）
     *   - expiredTime int     订单过期时间（秒）
     *   - authCode    string  付款码（条码支付必须）
     *   - openid      string  用户openid（JSAPI支付必须）
     * @return array
     * @throws YuePayException
     */
    public function unifiedOrder(array $params): array
    {
        $this->validateOrderParams($params);

        $config = $this->client->getConfig();

        $requestData = [
            'mchOrderNo'  => $params['mchOrderNo'],
            'wayCode'     => $params['wayCode'] ?? $config['default_way_code'],
            'amount'      => (int) $params['amount'],
            'currency'    => $config['currency'],
            'subject'     => $params['subject'],
            'body'        => $params['body'] ?? '',
            'clientIp'    => $params['clientIp'] ?? $this->getClientIp(),
            'notifyUrl'   => $params['notifyUrl'] ?? $config['notify_url'],
            'returnUrl'   => $params['returnUrl'] ?? '',
            'channelExtra' => $params['channelExtra'] ?? '',
            'expiredTime' => $params['expiredTime'] ?? 0,
        ];

        // JSAPI 支付需要 openid
        if (isset($params['openid'])) {
            $channelExtra = $params['channelExtra'] ?? '{}';
            $extraArr = json_decode($channelExtra, true) ?: [];
            $extraArr['openid'] = $params['openid'];
            $requestData['channelExtra'] = json_encode($extraArr, JSON_UNESCAPED_UNICODE);
        }

        // 条码支付需要 authCode
        if (isset($params['authCode'])) {
            $channelExtra = $requestData['channelExtra'] ?: '{}';
            $extraArr = json_decode($channelExtra, true) ?: [];
            $extraArr['authCode'] = $params['authCode'];
            $requestData['channelExtra'] = json_encode($extraArr, JSON_UNESCAPED_UNICODE);
        }

        // 过滤空字符串（保留0值）
        $requestData = array_filter($requestData, function ($v) {
            return $v !== '';
        });

        return $this->client->post(self::URI_UNIFIED_ORDER, $requestData);
    }

    /**
     * 查询支付订单
     *
     * @param string      $mchOrderNo   商户订单号
     * @param string|null $payOrderId   粤收付支付订单号（与mchOrderNo二选一）
     * @return array
     * @throws YuePayException
     */
    public function queryOrder(string $mchOrderNo = '', ?string $payOrderId = null): array
    {
        if (empty($mchOrderNo) && empty($payOrderId)) {
            throw new YuePayException('商户订单号和支付订单号不能同时为空');
        }

        $params = [];
        if ($payOrderId) {
            $params['payOrderId'] = $payOrderId;
        }
        if ($mchOrderNo) {
            $params['mchOrderNo'] = $mchOrderNo;
        }

        return $this->client->post(self::URI_QUERY_ORDER, $params);
    }

    /**
     * 关闭订单
     *
     * @param string      $mchOrderNo  商户订单号
     * @param string|null $payOrderId  粤收付支付订单号
     * @return array
     * @throws YuePayException
     */
    public function closeOrder(string $mchOrderNo = '', ?string $payOrderId = null): array
    {
        if (empty($mchOrderNo) && empty($payOrderId)) {
            throw new YuePayException('商户订单号和支付订单号不能同时为空');
        }

        $params = [];
        if ($payOrderId) {
            $params['payOrderId'] = $payOrderId;
        }
        if ($mchOrderNo) {
            $params['mchOrderNo'] = $mchOrderNo;
        }

        return $this->client->post(self::URI_CLOSE_ORDER, $params);
    }

    /**
     * 申请退款
     *
     * @param array $params 退款参数：
     *   - mchOrderNo     string  原商户订单号
     *   - payOrderId     string  原粤收付支付订单号（与mchOrderNo二选一）
     *   - mchRefundNo    string  商户退款单号（必须唯一）
     *   - refundAmount   int     退款金额，单位：分
     *   - refundReason   string  退款原因（可选）
     *   - notifyUrl      string  退款异步通知地址（可选）
     * @return array
     * @throws YuePayException
     */
    public function refund(array $params): array
    {
        if (empty($params['mchOrderNo']) && empty($params['payOrderId'])) {
            throw new YuePayException('原商户订单号和支付订单号不能同时为空');
        }
        if (empty($params['mchRefundNo'])) {
            throw new YuePayException('商户退款单号不能为空');
        }
        if (!isset($params['refundAmount']) || $params['refundAmount'] <= 0) {
            throw new YuePayException('退款金额必须大于0');
        }

        $config = $this->client->getConfig();

        $requestData = [
            'mchOrderNo'   => $params['mchOrderNo'] ?? '',
            'payOrderId'   => $params['payOrderId'] ?? '',
            'mchRefundNo'  => $params['mchRefundNo'],
            'refundAmount' => (int) $params['refundAmount'],
            'currency'     => $config['currency'],
            'refundReason' => $params['refundReason'] ?? '',
            'notifyUrl'    => $params['notifyUrl'] ?? $config['notify_url'],
        ];

        // 过滤空值
        $requestData = array_filter($requestData, function ($v) {
            return $v !== '';
        });

        return $this->client->post(self::URI_REFUND, $requestData);
    }

    /**
     * 查询退款订单
     *
     * @param string      $mchRefundNo  商户退款单号
     * @param string|null $refundOrderId 粤收付退款订单号
     * @return array
     * @throws YuePayException
     */
    public function queryRefund(string $mchRefundNo = '', ?string $refundOrderId = null): array
    {
        if (empty($mchRefundNo) && empty($refundOrderId)) {
            throw new YuePayException('商户退款单号和退款订单号不能同时为空');
        }

        $params = [];
        if ($refundOrderId) {
            $params['refundOrderId'] = $refundOrderId;
        }
        if ($mchRefundNo) {
            $params['mchRefundNo'] = $mchRefundNo;
        }

        return $this->client->post(self::URI_QUERY_REFUND, $params);
    }

    /**
     * 验证下单参数
     */
    private function validateOrderParams(array $params): void
    {
        $required = ['mchOrderNo', 'amount', 'subject'];
        foreach ($required as $field) {
            if (!isset($params[$field]) || $params[$field] === '') {
                throw new YuePayException("缺少必填参数: {$field}");
            }
        }

        if (!is_numeric($params['amount']) || (int) $params['amount'] <= 0) {
            throw new YuePayException('支付金额必须为大于0的整数（单位：分）');
        }
    }

    /**
     * 获取客户端IP
     */
    private function getClientIp(): string
    {
        if (app()->runningInConsole()) {
            return '127.0.0.1';
        }

        $request = request();
        $ip = $request->ip();

        return $ip ?: '127.0.0.1';
    }
}
