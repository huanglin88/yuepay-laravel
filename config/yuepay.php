<?php

/**
 * 粤收付支付配置文件
 */

return [

    // 接口基础地址（全部接口统一使用此域名）
    'base_url' => env('YUEPAY_BASE_URL', 'https://pay.cnyepay.com'),

    // 商户号
    'mch_no' => env('YUEPAY_MCH_NO', ''),

    // 应用ID
    'app_id' => env('YUEPAY_APP_ID', ''),

    // 商户RSA私钥（PKCS#8格式，用于生成签名）
    // 支持文件路径或直接填写私钥内容
    'private_key' => env('YUEPAY_PRIVATE_KEY', ''),

    // 粤收付平台RSA公钥（用于验证平台返回数据签名）
    // 支持文件路径或直接填写公钥内容
    'platform_public_key' => env('YUEPAY_PLATFORM_PUBLIC_KEY', ''),

    // 签名类型
    'sign_type' => 'RSA2',

    // 接口版本号
    'version' => '1.0',

    // 币种
    'currency' => 'CNY',

    // 默认异步通知地址
    'notify_url' => env('YUEPAY_NOTIFY_URL', ''),

    // HTTP超时时间（秒）
    'timeout' => 30,

    // 默认支付方式
    'default_way_code' => env('YUEPAY_DEFAULT_WAY_CODE', 'ALI_JSAPI'),

];
