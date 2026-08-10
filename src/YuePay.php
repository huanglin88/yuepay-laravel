<?php

namespace YuePay;

use Illuminate\Support\Facades\Facade;

/**
 * 粤收付支付门面
 *
 * @method static array unifiedOrder(array $params)                        统一下单
 * @method static array queryOrder(string $mchOrderNo = '', ?string $payOrderId = null)  查询支付订单
 * @method static array closeOrder(string $mchOrderNo = '', ?string $payOrderId = null)  关闭订单
 * @method static array refund(array $params)                              申请退款
 * @method static array queryRefund(string $mchRefundNo = '', ?string $refundOrderId = null)  查询退款订单
 * @method static array transferOrder(array $params)                       转账下单
 * @method static array queryTransfer(string $mchOrderNo = '', ?string $transferId = null)  查询转账订单
 * @method static array queryTransferBalance(string $ifCode)               查询转账可用余额
 * @method static array cashoutOrder(array $params)                        手动提现
 * @method static array queryCashout(string $mchOrderNo = '', ?string $cashoutOrderId = null)  查询提现详情
 * @method static array queryBalance(string $ifCode, string $channelExtra = '')  余额查询
 * @method static array bindDivisionReceiver(array $params)                绑定分账用户
 * @method static array execDivision(array $params)                        发起订单分账
 * @method static array queryDivision(array $params)                       查询订单分账
 * @method static array queryDivisionReceiverBalance(int $receiverId)      查询分账用户可用余额
 * @method static array cashoutDivisionReceiverBalance(int $receiverId, int $cashoutAmount)  分账用户余额提现
 * @method static array queryOpenIdByBarcode(string $barCode, ?string $subAppId = null)  条码换取openId
 * @method static string getChannelUserIdUrl(string $ifCode, string $redirectUrl)  获取渠道用户ID跳转链接
 *
 * @see \YuePay\YuePayService
 */
class YuePay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return YuePayService::class;
    }
}
