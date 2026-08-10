<?php

namespace YuePay;

use Illuminate\Support\Facades\Facade;

/**
 * 粤收付支付门面
 *
 * @method static array unifiedOrder(array $params)          统一下单
 * @method static array queryOrder(string $mchOrderNo = '', ?string $payOrderId = null)  查询支付订单
 * @method static array closeOrder(string $mchOrderNo = '', ?string $payOrderId = null)  关闭订单
 * @method static array refund(array $params)                申请退款
 * @method static array queryRefund(string $mchRefundNo = '', ?string $refundOrderId = null)  查询退款订单
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
