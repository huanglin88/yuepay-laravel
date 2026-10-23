<?php

/**
 * 粤收付 V2.0（开发平台 2.0）路由
 *
 * 与 V1.0 的 routes/web.php（前缀 yuepay）完全独立，本文件前缀为 yuepay/v2，互不冲突。
 *
 * 在 Laravel routes/api.php 中按需引入：
 *   Route::prefix('api')->group(base_path('vendor/yuepay/routes/v2.php'));
 *
 * 引入后接口路径形如：
 *   POST /api/yuepay/v2/order
 *   POST /api/yuepay/v2/refund
 *   POST /api/yuepay/v2/notify
 */

use Illuminate\Support\Facades\Route;
use YuePay\V2\Http\Controllers\V2Controller;

Route::prefix('yuepay/v2')->group(function () {

    // 统一下单（按 wayCode 自动路由微信/支付宝/银联）
    Route::post('order', [V2Controller::class, 'createOrder'])
        ->name('yuepay.v2.order');

    // 收银台预下单
    Route::post('pre-order', [V2Controller::class, 'preOrder'])
        ->name('yuepay.v2.pre_order');

    // 查询支付订单
    Route::get('query', [V2Controller::class, 'queryOrder'])
        ->name('yuepay.v2.query');

    // 关闭订单
    Route::post('close', [V2Controller::class, 'closeOrder'])
        ->name('yuepay.v2.close');

    // 统一退款
    Route::post('refund', [V2Controller::class, 'refund'])
        ->name('yuepay.v2.refund');

    // 查询退款订单
    Route::get('refund/query', [V2Controller::class, 'queryRefund'])
        ->name('yuepay.v2.refund.query');

    // 请求分账
    Route::post('division/apply', [V2Controller::class, 'divisionApply'])
        ->name('yuepay.v2.division.apply');

    // 查询分账
    Route::post('division/query', [V2Controller::class, 'divisionQuery'])
        ->name('yuepay.v2.division.query');

    // 发起分账回退
    Route::post('split/refund', [V2Controller::class, 'splitRefund'])
        ->name('yuepay.v2.split.refund');

    // 获取渠道用户ID跳转链接
    Route::get('channel-user-id/url', [V2Controller::class, 'channelUserIdUrl'])
        ->name('yuepay.v2.channel_user_id');

    // 支付结果异步回调（无需 CSRF 令牌）
    Route::post('notify', [V2Controller::class, 'notify'])
        ->name('yuepay.v2.notify')
        ->withoutMiddleware(['web']);

});
