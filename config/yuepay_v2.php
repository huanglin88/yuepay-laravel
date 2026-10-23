<?php

/**
 * 粤收付开发平台 2.0（V2.0）配置文件
 *
 * 与 V1.0 的 config/yuepay.php 完全独立，互不影响。
 * V2.0 网关：https://pay.cnyepay.com，接口统一前缀 /api/v2/
 *
 * ⚠️ 说明：V2.0 官方发布文档在个别字段命名上存在内部不一致（详见 README「V2.0 接入」），
 * 本文件提供 appid_field / expire_time_field 两个开关，便于联调时切换，无需改代码。
 */

return [

    // V2.0 网关地址（支付/退款/分账统一使用此域名）
    'base_url' => env('YUEPAY_V2_BASE_URL', 'https://pay.cnyepay.com'),

    // 商户号
    'mch_no' => env('YUEPAY_V2_MCH_NO', env('YUEPAY_MCH_NO', '')),

    // 机构号（V2.0 新增且必填）
    // 商户调用=商户号；合作伙伴=合作伙伴号；SAAS 调用=SAAS 方号
    'org_no' => env('YUEPAY_V2_ORG_NO', ''),

    // 粤收付应用 AppID（选填，不传则由平台走默认/路由配置）
    'appid' => env('YUEPAY_V2_APP_ID', ''),

    // 商户 RSA 私钥（PKCS#8 格式），支持文件路径或直接填写内容
    'private_key' => env('YUEPAY_V2_PRIVATE_KEY', env('YUEPAY_PRIVATE_KEY', '')),

    // 粤收付平台 RSA 公钥（用于校验响应/回调签名），支持文件路径或直接填写内容
    'platform_public_key' => env('YUEPAY_V2_PLATFORM_PUBLIC_KEY', env('YUEPAY_PLATFORM_PUBLIC_KEY', '')),

    // 币种
    'currency' => 'CNY',

    // 默认异步通知地址
    'notify_url' => env('YUEPAY_V2_NOTIFY_URL', env('YUEPAY_NOTIFY_URL', '')),

    // HTTP 超时时间（秒）
    'timeout' => 30,

    // 默认支付方式（V2.0 wayCode，A_/T_/U_ 前缀）
    'default_way_code' => env('YUEPAY_V2_DEFAULT_WAY_CODE', 'A_NATIVE'),

    // 是否校验平台响应签名
    // 官方 2.0 发布文档未明确响应 sign 的待签串构成，联调期如遇验签不一致可临时置 false 排查
    'verify_response_sign' => (bool) env('YUEPAY_V2_VERIFY_SIGN', true),

    // 业务成功码
    // 官方 2.0 未给出 code 枚举，先沿用 0/SUCCESS；联调后按实际返回调整
    'success_codes' => [0, '0', 'SUCCESS', 'success'],

    // 订单默认过期时间（秒，官方上限 7200）
    'expire_time' => (int) env('YUEPAY_V2_EXPIRE_TIME', 7200),

    // AppID 字段名
    // 官方接口 schema 为 appid（全小写）；签名示例页为 appId（驼峰）。默认以 schema 为准
    'appid_field' => env('YUEPAY_V2_APPID_FIELD', 'appid'),

    // 订单过期时间字段名
    // 官方接口 schema 为 expireTime；签名示例页为 expiredTime。默认以 schema 为准
    'expire_time_field' => env('YUEPAY_V2_EXPIRE_TIME_FIELD', 'expireTime'),
];
