<?php

namespace YuePay\Callback;

use Illuminate\Http\Request;
use YuePay\Exceptions\YuePayException;
use YuePay\Support\SignHelper;

/**
 * 粤收付回调通知处理器
 *
 * 处理平台异步通知（支付结果回调、退款结果回调）
 *
 * 回调验签流程：
 * 1. 获取通知参数中的 sign
 * 2. 对除 sign 外的参数按 ASCII 字典序排序拼接
 * 3. 使用粤收付平台公钥验签
 * 4. 验签通过后处理业务逻辑
 * 5. 返回 "success" 告知平台已收到
 */
class CallbackHandler
{
    private string $platformPublicKey;

    public function __construct(string $platformPublicKey)
    {
        $this->platformPublicKey = $platformPublicKey;
    }

    /**
     * 解析并验证回调通知
     *
     * @param Request $request Laravel Request
     * @return array 验签通过后的回调数据
     * @throws YuePayException
     */
    public function handle(Request $request): array
    {
        // 获取回调参数（可能是 JSON 或 form-data）
        $data = $request->isJson()
            ? $request->json()->all()
            : $request->all();

        if (empty($data)) {
            throw new YuePayException('回调数据为空');
        }

        // 验签
        $verified = SignHelper::verify($data, $this->platformPublicKey);

        if (!$verified) {
            throw YuePayException::signVerifyFailed();
        }

        return $data;
    }

    /**
     * 解析支付回调数据
     *
     * 返回标准化结构：
     * [
     *   'payOrderId'    => 粤收付支付订单号,
     *   'mchOrderNo'    => 商户订单号,
     *   'wayCode'       => 支付方式,
     *   'amount'        => 支付金额（分）,
     *   'successAmount' => 成功金额（分）,
     *   'state'         => 支付状态（2=成功, 3=失败, 4=关闭, 5=退款）,
     *   'clientIp'      => 客户端IP,
     *   'paySuccTime'   => 支付成功时间,
     *   'backType'      => 回调类型（支付/退款）,
     *   'rawData'       => 原始数据,
     * ]
     *
     * @param array $data 已验签的回调数据
     * @return array
     */
    public function parsePaymentNotify(array $data): array
    {
        // 支持 data 嵌套结构
        $notifyData = $data['data'] ?? $data;

        return [
            'payOrderId'    => $notifyData['payOrderId'] ?? '',
            'mchOrderNo'    => $notifyData['mchOrderNo'] ?? '',
            'wayCode'       => $notifyData['wayCode'] ?? '',
            'amount'        => (int) ($notifyData['amount'] ?? 0),
            'successAmount' => (int) ($notifyData['successAmount'] ?? 0),
            'state'         => $notifyData['state'] ?? 0,
            'clientIp'      => $notifyData['clientIp'] ?? '',
            'paySuccTime'   => $notifyData['paySuccTime'] ?? null,
            'backType'      => $notifyData['backType'] ?? 'PAY',
            'rawData'       => $data,
        ];
    }

    /**
     * 解析退款回调数据
     *
     * 返回标准化结构：
     * [
     *   'refundOrderId' => 粤收付退款订单号,
     *   'mchRefundNo'   => 商户退款单号,
     *   'payOrderId'    => 原支付订单号,
     *   'mchOrderNo'    => 原商户订单号,
     *   'refundAmount'  => 退款金额（分）,
     *   'refundState'   => 退款状态（2=成功, 3=失败）,
     *   'refundSuccTime'=> 退款成功时间,
     *   'rawData'       => 原始数据,
     * ]
     *
     * @param array $data 已验签的回调数据
     * @return array
     */
    public function parseRefundNotify(array $data): array
    {
        $notifyData = $data['data'] ?? $data;

        return [
            'refundOrderId'  => $notifyData['refundOrderId'] ?? '',
            'mchRefundNo'    => $notifyData['mchRefundNo'] ?? '',
            'payOrderId'     => $notifyData['payOrderId'] ?? '',
            'mchOrderNo'     => $notifyData['mchOrderNo'] ?? '',
            'refundAmount'   => (int) ($notifyData['refundAmount'] ?? 0),
            'refundState'    => $notifyData['state'] ?? $notifyData['refundState'] ?? 0,
            'refundSuccTime' => $notifyData['refundSuccTime'] ?? null,
            'rawData'        => $data,
        ];
    }

    /**
     * 解析转账回调数据
     *
     * 返回标准化结构：
     * [
     *   'transferId'       => 粤收付转账订单号,
     *   'mchOrderNo'       => 商户转账单号,
     *   'amount'           => 转账金额（分）,
     *   'state'            => 转账状态（2=成功, 3=失败）,
     *   'accountNo'        => 收款账号,
     *   'accountName'      => 收款方姓名,
     *   'channelOrderNo'   => 渠道转账单号,
     *   'successTime'      => 转账成功时间,
     *   'errCode'          => 错误码,
     *   'errMsg'           => 错误描述,
     *   'rawData'          => 原始数据,
     * ]
     *
     * @param array $data 已验签的回调数据
     * @return array
     */
    public function parseTransferNotify(array $data): array
    {
        $notifyData = $data['data'] ?? $data;

        return [
            'transferId'     => $notifyData['transferId'] ?? '',
            'mchOrderNo'     => $notifyData['mchOrderNo'] ?? '',
            'amount'         => (int) ($notifyData['amount'] ?? 0),
            'state'          => $notifyData['state'] ?? 0,
            'accountNo'      => $notifyData['accountNo'] ?? '',
            'accountName'    => $notifyData['accountName'] ?? '',
            'channelOrderNo' => $notifyData['channelOrderNo'] ?? '',
            'successTime'    => $notifyData['successTime'] ?? null,
            'errCode'        => $notifyData['errCode'] ?? '',
            'errMsg'         => $notifyData['errMsg'] ?? '',
            'rawData'        => $data,
        ];
    }

    /**
     * 生成成功响应（返回给粤收付平台）
     * 平台要求返回 "success" 表示已正确接收通知
     */
    public function successResponse(): string
    {
        return 'success';
    }

    /**
     * 生成失败响应
     */
    public function failResponse(string $message = 'fail'): string
    {
        return $message;
    }
}
