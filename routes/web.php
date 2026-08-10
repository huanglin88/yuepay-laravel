<?php

/**
 * 粤收付支付路由
 *
 * 在 Laravel routes/api.php 或 routes/web.php 中引入：
 * Route::prefix('yuepay')->group(base_path('vendor/yuepay/routes/web.php'));
 */

use Illuminate\Support\Facades\Route;
use YuePay\Http\Controllers\YuePayController;

Route::prefix('yuepay')->group(function () {

    // 统一下单
    Route::post('order', [YuePayController::class, 'createOrder'])
        ->name('yuepay.order');

    // 查询支付订单
    Route::get('query', [YuePayController::class, 'queryOrder'])
        ->name('yuepay.query');

    // 关闭订单
    Route::post('close', [YuePayController::class, 'closeOrder'])
        ->name('yuepay.close');

    // 申请退款
    Route::post('refund', [YuePayController::class, 'refund'])
        ->name('yuepay.refund');

    // 查询退款
    Route::get('refund/query', [YuePayController::class, 'queryRefund'])
        ->name('yuepay.refund.query');

    // 支付结果异步回调（无需CSRF令牌）
    Route::post('notify', [YuePayController::class, 'notify'])
        ->name('yuepay.notify')
        ->withoutMiddleware(['web']);

});
