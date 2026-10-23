<?php

namespace YuePay\V2;

use Illuminate\Support\Facades\Facade;

/**
 * 粤收付 V2.0 支付门面
 *
 * 与 V1.0 的 {@see \YuePay\YuePay} 并存，互不影响。
 * 在 config/app.php 的 aliases 中注册：'YuePayV2' => YuePay\V2\V2Facade::class
 *
 * @method static array pay(array $params)                                 统一下单（按 wayCode 自动路由渠道）
 * @method static array wechatOrder(array $params)                         微信支付下单
 * @method static array alipayOrder(array $params)                         支付宝支付下单
 * @method static array unionpayOrder(array $params)                       银联二维码支付下单
 * @method static array preOrder(array $params)                            收银台预下单
 * @method static array closeOrder(string $payOrderId = '', string $mchOrderNo = '', string $requestNo = '')  关闭订单
 * @method static array queryOrder(string $payOrderId = '', string $mchOrderNo = '', string $requestNo = '', string $orderDate = '')  查询支付订单
 * @method static array refund(array $params)                              统一退款
 * @method static array queryRefund(string $refundOrderId = '', string $mchRefundNo = '', string $requestNo = '', string $orderDate = '')  查询退款订单
 * @method static array divisionApply(array $params)                       请求分账
 * @method static array divisionQuery(array $params)                       查询分账
 * @method static array splitRefund(array $params)                         发起分账回退
 * @method static array splitRefundQuery(array $params)                    分账回退查询
 * @method static string channelUserIdUrl(array $params)                   获取渠道用户ID跳转链接
 *
 * @see \YuePay\V2\V2Service
 */
class V2Facade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return V2Service::class;
    }
}
