<?php

namespace YuePay;

use YuePay\Exceptions\YuePayException;
use YuePay\Support\HttpClient;

/**
 * 粤收付支付服务
 *
 * 封装粤收付开放平台全部接口：
 * - 统一下单（支持支付宝/微信多种支付方式）
 * - 支付订单查询 / 关闭订单
 * - 退款申请 / 退款查询
 * - 转账下单 / 转账查询 / 转账余额查询
 * - 手动���现 / 提现查询 / 余额查询
 * - 绑定分账用户 / 发起分账 / 查询分账 / 分账用户余额查询 / 分账用户余额提现
 * - 条码换openId / 获取渠道用户ID
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
    // 转账
    private const URI_TRANSFER_ORDER          = '/api/transferOrder';
    private const URI_QUERY_TRANSFER          = '/api/transfer/query';
    private const URI_TRANSFER_BALANCE        = '/api/transfer/balance/query';
    // 提现
    private const URI_CASHOUT_ORDER           = '/api/cashout/order/create';
    private const URI_QUERY_CASHOUT           = '/api/cashout/order/query';
    private const URI_CASHOUT_BALANCE         = '/api/cashout/balance/query';
    // 分账
    private const URI_BIND_DIVISION_RECEIVER  = '/api/division/receiver/bind';
    private const URI_DIVISION_EXEC           = '/api/division/exec';
    private const URI_QUERY_DIVISION          = '/api/division/query';
    private const URI_DIVISION_RECEIVER_BALANCE = '/api/division/receiver/channelBalanceQuery';
    private const URI_DIVISION_RECEIVER_CASHOUT = '/api/division/receiver/channelBalanceCashout';
    // 支付辅助
    private const URI_QUERY_OPENID_BY_BARCODE = '/api/pay/queryOpenIdByBarcode';
    private const URI_CHANNEL_USERID_JUMP     = '/api/channelUserId/jump';

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
     *   - expiredTime int/string  订单过期时间（13位毫秒时间戳，可选）
     *   - orderExpireTime string 同上，别名
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
            'orderExpireTime' => $params['expiredTime'] ?? $params['orderExpireTime'] ?? '',
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
     * 转账下单
     *
     * @param array $params 转账参数：
     *   - mchOrderNo   string  商户转账单号（必填）
     *   - amount       int     转账金额，单位：分（必填）
     *   - accountNo    string  收款账号（微信openid或银行卡号）
     *   - accountName  string  收款方姓名
     *   - ifCode       string  接口代码（如 wxpay, alipay）
     *   - entryType    string  入账方式（如 WX_CASH 微信零钱, BANK_CARD 银行卡）
     *   - bankName     string  银行名称（银行卡转账时必填）
     *   - clientIp     string  客户端IP
     *   - transferDesc string  转账描述/备注
     *   - notifyUrl    string  异步通知地址
     *   - channelExtra string  渠道扩展参数（JSON字符串）
     *   - extParam     string  扩展参数
     * @return array 响应包含 transferId, mchOrderNo, state, channelOrderNo 等
     * @throws YuePayException
     */
    public function transferOrder(array $params): array
    {
        if (empty($params['mchOrderNo'])) {
            throw new YuePayException('商户转账单号不能为空');
        }
        if (empty($params['amount']) || (int) $params['amount'] <= 0) {
            throw new YuePayException('转账金额必须大于0（单位：分）');
        }

        $config = $this->client->getConfig();

        $requestData = [
            'mchOrderNo'   => $params['mchOrderNo'],
            'ifCode'       => $params['ifCode'] ?? '',
            'entryType'    => $params['entryType'] ?? '',
            'amount'       => (int) $params['amount'],
            'currency'     => $config['currency'],
            'accountNo'    => $params['accountNo'] ?? '',
            'accountName'  => $params['accountName'] ?? '',
            'bankName'     => $params['bankName'] ?? '',
            'clientIp'     => $params['clientIp'] ?? $this->getClientIp(),
            'transferDesc' => $params['transferDesc'] ?? '',
            'notifyUrl'    => $params['notifyUrl'] ?? $config['notify_url'],
            'channelExtra' => $params['channelExtra'] ?? '',
            'extParam'     => $params['extParam'] ?? '',
        ];

        // 过滤空字符串
        $requestData = array_filter($requestData, function ($v) {
            return $v !== '';
        });

        return $this->client->post(self::URI_TRANSFER_ORDER, $requestData);
    }

    /**
     * 查询转账订单
     *
     * @param string      $mchOrderNo  商户转账单号
     * @param string|null $transferId  粤收付转账订单号
     * @return array
     * @throws YuePayException
     */
    public function queryTransfer(string $mchOrderNo = '', ?string $transferId = null): array
    {
        if (empty($mchOrderNo) && empty($transferId)) {
            throw new YuePayException('商户转账单号和转账订单号不能同时为空');
        }

        $params = [];
        if ($transferId) {
            $params['transferId'] = $transferId;
        }
        if ($mchOrderNo) {
            $params['mchOrderNo'] = $mchOrderNo;
        }

        return $this->client->post(self::URI_QUERY_TRANSFER, $params);
    }

    /**
     * 查询转账可用余额
     *
     * @param string $ifCode 接口代码（如 wxpay, alipay, aliaqfpay）
     * @return array 响应包含 balanceAmount
     * @throws YuePayException
     */
    public function queryTransferBalance(string $ifCode): array
    {
        if (empty($ifCode)) {
            throw new YuePayException('接口代码(ifCode)不能为空');
        }

        return $this->client->post(self::URI_TRANSFER_BALANCE, [
            'ifCode' => $ifCode,
        ]);
    }

    /**
     * 手动提现
     *
     * @param array $params 提现参数：
     *   - mchOrderNo   string  商户提现单号（必填）
     *   - amount       int     提现金额，单位：分（必填）
     *   - ifCode       string  接口代码（如 dgpay）
     *   - channelExtra string  渠道扩展参数（JSON字符串）
     *   - remark       string  备注
     * @return array 响应包含 cashoutOrderId, mchOrderNo, state
     * @throws YuePayException
     */
    public function cashoutOrder(array $params): array
    {
        if (empty($params['mchOrderNo'])) {
            throw new YuePayException('商户提现单号不能为空');
        }
        if (empty($params['amount']) || (int) $params['amount'] <= 0) {
            throw new YuePayException('提现金额必须大于0（单位：分）');
        }

        $requestData = [
            'mchOrderNo'   => $params['mchOrderNo'],
            'amount'       => (int) $params['amount'],
            'ifCode'       => $params['ifCode'] ?? '',
            'channelExtra' => $params['channelExtra'] ?? '',
            'remark'       => $params['remark'] ?? '',
        ];

        $requestData = array_filter($requestData, function ($v) {
            return $v !== '';
        });

        return $this->client->post(self::URI_CASHOUT_ORDER, $requestData);
    }

    /**
     * 查询提现详情
     *
     * @param string      $mchOrderNo     商户提现单号
     * @param string|null $cashoutOrderId 粤收付提现订单号
     * @return array 响应包含 cashoutOrderId, state, amount, bankName, accountNo 等
     * @throws YuePayException
     */
    public function queryCashout(string $mchOrderNo = '', ?string $cashoutOrderId = null): array
    {
        if (empty($mchOrderNo) && empty($cashoutOrderId)) {
            throw new YuePayException('商户提现单号和提现订单号不能同时为空');
        }

        $params = [];
        if ($cashoutOrderId) {
            $params['cashoutOrderId'] = $cashoutOrderId;
        }
        if ($mchOrderNo) {
            $params['mchOrderNo'] = $mchOrderNo;
        }

        return $this->client->post(self::URI_QUERY_CASHOUT, $params);
    }

    /**
     * 余额查询（商户账户余额）
     *
     * @param string $ifCode       接口代码（如 dgpay）
     * @param string $channelExtra 渠道扩展参数（JSON字符串，可选）
     * @return array 响应包含 balance, frozenBalance, totalBalance
     * @throws YuePayException
     */
    public function queryBalance(string $ifCode, string $channelExtra = ''): array
    {
        $params = ['ifCode' => $ifCode];
        if ($channelExtra !== '') {
            $params['channelExtra'] = $channelExtra;
        }

        return $this->client->post(self::URI_CASHOUT_BALANCE, $params);
    }

    /**
     * 绑定分账用户
     *
     * @param array $params 绑定参数：
     *   - ifCode            string  接口代码（如 wxpay）
     *   - receiverAlias     string  分账接收方别名
     *   - receiverGroupId   int     分账接收方组ID
     *   - accType           int     账户类型（1=个人, 0=企业）
     *   - accNo             string  接收方账号（微信openid/支付宝userId）
     *   - accName           string  接收方姓名
     *   - relationType      string  分账关系类型（如 SERVICE_PROVIDER）
     *   - relationTypeName  string  分账关系类型名称（可选）
     *   - channelExtInfo    string  渠道扩展信息（JSON字符串，可选）
     *   - divisionProfit    string  分账比例（如 "0.3" 表示30%）
     * @return array 响应包含 receiverId, bindState 等
     * @throws YuePayException
     */
    public function bindDivisionReceiver(array $params): array
    {
        $required = ['ifCode', 'receiverAlias', 'receiverGroupId', 'accType', 'accNo', 'accName', 'relationType', 'divisionProfit'];
        foreach ($required as $field) {
            if (!isset($params[$field]) || $params[$field] === '') {
                throw new YuePayException("缺少必填参数: {$field}");
            }
        }

        $requestData = [
            'ifCode'            => $params['ifCode'],
            'receiverAlias'     => $params['receiverAlias'],
            'receiverGroupId'   => (int) $params['receiverGroupId'],
            'accType'           => (int) $params['accType'],
            'accNo'             => $params['accNo'],
            'accName'           => $params['accName'],
            'relationType'      => $params['relationType'],
            'relationTypeName'  => $params['relationTypeName'] ?? '',
            'channelExtInfo'    => $params['channelExtInfo'] ?? '',
            'divisionProfit'    => $params['divisionProfit'],
        ];

        $requestData = array_filter($requestData, function ($v) {
            return $v !== '';
        });

        return $this->client->post(self::URI_BIND_DIVISION_RECEIVER, $requestData);
    }

    /**
     * 发起订单分账
     *
     * 当统一下单时 divisionMode=2（手动分账），通过此接口发起分账。
     *
     * @param array $params 分账参数：
     *   - payOrderId                   string  粤收付支付订单号
     *   - mchOrderNo                   string  商户订单号（与payOrderId二选一）
     *   - useSysAutoDivisionReceivers  int     是否使用系统自动分账接收方（1=是, 0=否）
     *   - receivers                    string  自定义分账接收方列表（JSON字符串）
     * @return array 响应包含 state, batchOrderId, channelBatchOrderId
     * @throws YuePayException
     */
    public function execDivision(array $params): array
    {
        if (empty($params['payOrderId']) && empty($params['mchOrderNo'])) {
            throw new YuePayException('支付订单号和商户订单号不能同时为空');
        }

        $requestData = [
            'payOrderId'                  => $params['payOrderId'] ?? '',
            'mchOrderNo'                  => $params['mchOrderNo'] ?? '',
            'useSysAutoDivisionReceivers' => $params['useSysAutoDivisionReceivers'] ?? 1,
            'receivers'                   => $params['receivers'] ?? '',
        ];

        $requestData = array_filter($requestData, function ($v) {
            return $v !== '';
        });

        return $this->client->post(self::URI_DIVISION_EXEC, $requestData);
    }

    /**
     * 查询订单分账结果
     *
     * @param array $params 查询参数：
     *   - payOrderId   string  粤收付支付订单号
     *   - mchOrderNo   string  商户订单号（与payOrderId二选一）
     *   - batchOrderId string  分账批次号（可选）
     *   - receiverId   int     分账接收方ID（可选）
     * @return array 响应包含 records（分账记录JSON字符串）
     * @throws YuePayException
     */
    public function queryDivision(array $params): array
    {
        $requestData = [
            'payOrderId'   => $params['payOrderId'] ?? '',
            'mchOrderNo'   => $params['mchOrderNo'] ?? '',
            'batchOrderId' => $params['batchOrderId'] ?? '',
            'receiverId'   => $params['receiverId'] ?? '',
        ];

        $requestData = array_filter($requestData, function ($v) {
            return $v !== '';
        });

        return $this->client->post(self::URI_QUERY_DIVISION, $requestData);
    }

    /**
     * 查询分账用户可用余额
     *
     * @param int $receiverId 分账接收方ID
     * @return array 响应包含 receiverId, balanceAmount
     * @throws YuePayException
     */
    public function queryDivisionReceiverBalance(int $receiverId): array
    {
        if ($receiverId <= 0) {
            throw new YuePayException('分账接收方ID不能为空');
        }

        return $this->client->post(self::URI_DIVISION_RECEIVER_BALANCE, [
            'receiverId' => $receiverId,
        ]);
    }

    /**
     * 对分账用户的余额发起提现
     *
     * 实时调起三方提现接口，将分账用户余额提现到结算银行卡。
     * 建议调用前先调用 queryDivisionReceiverBalance 查询余额。
     *
     * @param int $receiverId    分账接收方ID（由绑定接口返回）
     * @param int $cashoutAmount 提现金额，单位：分
     * @return array 响应包含 receiverId, state, errCode, errMsg
     * @throws YuePayException
     */
    public function cashoutDivisionReceiverBalance(int $receiverId, int $cashoutAmount): array
    {
        if ($receiverId <= 0) {
            throw new YuePayException('分账接收方ID不能为空');
        }
        if ($cashoutAmount <= 0) {
            throw new YuePayException('提现金额必须大于0');
        }

        return $this->client->post(self::URI_DIVISION_RECEIVER_CASHOUT, [
            'receiverId'    => $receiverId,
            'cashoutAmount' => $cashoutAmount,
        ]);
    }

    /**
     * 条码换取 openId
     *
     * 上送刷卡条码值，换取微信/支付宝的 openId/userId
     *
     * @param string      $barCode  付款码条码值
     * @param string|null $subAppId 子商户appId（可选）
     * @return array 响应包含 openId, subOpenId
     * @throws YuePayException
     */
    public function queryOpenIdByBarcode(string $barCode, ?string $subAppId = null): array
    {
        if (empty($barCode)) {
            throw new YuePayException('条码(barCode)不能为空');
        }

        $params = ['barCode' => $barCode];
        if ($subAppId !== null && $subAppId !== '') {
            $params['subAppId'] = $subAppId;
        }

        return $this->client->post(self::URI_QUERY_OPENID_BY_BARCODE, $params);
    }

    /**
     * 获取渠道用户ID跳转链接
     *
     * 通过页面跳转方式获取渠道用户ID（如微信openID），完成后跳转到商户指定的 redirectUrl
     *
     * @param string $ifCode      接口代码（如 wxpay, alipay）
     * @param string $redirectUrl 获取到用户ID后的跳转地址
     * @return string 完整的跳转URL（直接302跳转即可）
     * @throws YuePayException
     */
    public function getChannelUserIdUrl(string $ifCode, string $redirectUrl): string
    {
        if (empty($ifCode)) {
            throw new YuePayException('接口代码(ifCode)不能为空');
        }
        if (empty($redirectUrl)) {
            throw new YuePayException('跳转地址(redirectUrl)不能为空');
        }

        return $this->client->buildUrl(self::URI_CHANNEL_USERID_JUMP, [
            'ifCode'      => $ifCode,
            'redirectUrl' => $redirectUrl,
        ]);
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
