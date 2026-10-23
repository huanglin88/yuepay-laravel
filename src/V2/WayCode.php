<?php

namespace YuePay\V2;

use YuePay\Exceptions\YuePayException;

/**
 * 粤收付 V2.0 支付方式（wayCode）常量与渠道解析
 *
 * V2.0 与 V1.0 的 wayCode 命名规则不同：
 *   - V1.0：ALI_ / WX_ / YSF_ 前缀
 *   - V2.0：A_（支付宝）/ T_（微信）/ U_（银联云闪付）前缀
 *
 * 且 V2.0 的渠道专有参数收进独立对象：
 *   - 支付宝 alipayData{authCode, payDataType, buyerUserId}
 *   - 微信   wxpayData{authCode, openid, subAppId, payDataType}
 *   - 银联   unionpayData{frontUrl, payDataType, userId, authCode}
 */
class WayCode
{
    public const CHANNEL_ALIPAY   = 'alipay';
    public const CHANNEL_WECHAT   = 'wechat';
    public const CHANNEL_UNIONPAY = 'unionpay';

    // ---------- 支付宝 ----------
    public const ALIPAY_APP      = 'A_APP';        // 支付宝 APP
    public const ALIPAY_MICROPAY = 'A_MICROPAY';   // 支付宝条码（需 authCode）
    public const ALIPAY_WAP      = 'A_WAP';        // 支付宝 WAP
    public const ALIPAY_JSAPI    = 'A_JSAPI';      // 支付宝生活号（需 buyerUserId）
    public const ALIPAY_MINIAPP  = 'A_MINIAPP';    // 支付宝小程序（需 buyerUserId）
    public const ALIPAY_NATIVE   = 'A_NATIVE';     // 支付宝二维码

    // ---------- 微信 ----------
    public const WECHAT_APP      = 'T_APP';        // 微信 APP
    public const WECHAT_MICROPAY = 'T_MICROPAY';   // 微信条码（需 authCode）
    public const WECHAT_H5       = 'T_H5';         // 微信 H5
    public const WECHAT_JSAPI    = 'T_JSAPI';      // 微信公众号（需 openid + subAppId）
    public const WECHAT_MINIAPP  = 'T_MINIAPP';    // 微信小程序（需 openid + subAppId）
    public const WECHAT_NATIVE   = 'T_NATIVE';     // 微信扫码

    // ---------- 银联 / 云闪付 ----------
    public const UNIONPAY_APP      = 'U_APP';      // 云闪付 APP
    public const UNIONPAY_MICROPAY = 'U_MICROPAY'; // 银联条码（需 authCode）
    public const UNIONPAY_WAP      = 'U_WAP';      // 银联 WAP
    public const UNIONPAY_JSAPI    = 'U_JSAPI';    // 银联 JS 支付（需 userId）
    public const UNIONPAY_NATIVE   = 'U_NATIVE';   // 银联二维码

    /**
     * V2.0 全部 wayCode => 渠道
     */
    private const MAP = [
        self::ALIPAY_APP        => self::CHANNEL_ALIPAY,
        self::ALIPAY_MICROPAY   => self::CHANNEL_ALIPAY,
        self::ALIPAY_WAP        => self::CHANNEL_ALIPAY,
        self::ALIPAY_JSAPI      => self::CHANNEL_ALIPAY,
        self::ALIPAY_MINIAPP    => self::CHANNEL_ALIPAY,
        self::ALIPAY_NATIVE     => self::CHANNEL_ALIPAY,

        self::WECHAT_APP        => self::CHANNEL_WECHAT,
        self::WECHAT_MICROPAY   => self::CHANNEL_WECHAT,
        self::WECHAT_H5         => self::CHANNEL_WECHAT,
        self::WECHAT_JSAPI      => self::CHANNEL_WECHAT,
        self::WECHAT_MINIAPP    => self::CHANNEL_WECHAT,
        self::WECHAT_NATIVE     => self::CHANNEL_WECHAT,

        self::UNIONPAY_APP      => self::CHANNEL_UNIONPAY,
        self::UNIONPAY_MICROPAY => self::CHANNEL_UNIONPAY,
        self::UNIONPAY_WAP      => self::CHANNEL_UNIONPAY,
        self::UNIONPAY_JSAPI    => self::CHANNEL_UNIONPAY,
        self::UNIONPAY_NATIVE   => self::CHANNEL_UNIONPAY,
    ];

    /**
     * V1.0 wayCode => V2.0 wayCode（迁移参考，仅供参考，最终以商户已开通通道为准）
     */
    private const V1_TO_V2 = [
        'ALI_APP'    => self::ALIPAY_APP,
        'ALI_JSAPI'  => self::ALIPAY_JSAPI,
        'ALI_LITE'   => self::ALIPAY_MINIAPP,
        'ALI_BAR'    => self::ALIPAY_MICROPAY,
        'ALI_WAP'    => self::ALIPAY_WAP,
        'ALI_QR'     => self::ALIPAY_NATIVE,
        'ALI_PC'     => self::ALIPAY_NATIVE,

        'WX_APP'     => self::WECHAT_APP,
        'WX_JSAPI'   => self::WECHAT_JSAPI,
        'WX_LITE'    => self::WECHAT_MINIAPP,
        'WX_BAR'     => self::WECHAT_MICROPAY,
        'WX_H5'      => self::WECHAT_H5,
        'WX_NATIVE'  => self::WECHAT_NATIVE,

        'YSF_BAR'    => self::UNIONPAY_MICROPAY,
        'YSF_JSAPI'  => self::UNIONPAY_JSAPI,
    ];

    /**
     * 根据 wayCode 解析所属渠道
     *
     * @param string $wayCode V2.0 wayCode
     * @return string alipay|wechat|unionpay
     * @throws YuePayException
     */
    public static function channelOf(string $wayCode): string
    {
        $wayCode = strtoupper(trim($wayCode));

        if (isset(self::MAP[$wayCode])) {
            return self::MAP[$wayCode];
        }

        throw new YuePayException("不支持的 V2.0 wayCode: {$wayCode}");
    }

    /**
     * 该 wayCode 对应的渠道数据对象键名
     *
     * @param string $wayCode V2.0 wayCode
     * @return string wxpayData|alipayData|unionpayData
     */
    public static function dataKeyOf(string $wayCode): string
    {
        switch (self::channelOf($wayCode)) {
            case self::CHANNEL_WECHAT:
                return 'wxpayData';
            case self::CHANNEL_ALIPAY:
                return 'alipayData';
            default:
                return 'unionpayData';
        }
    }

    /**
     * 全部 V2.0 wayCode
     *
     * @return string[]
     */
    public static function all(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * V1.0 wayCode 转 V2.0 wayCode（无对应关系时返回 null）
     */
    public static function fromV1(string $v1WayCode): ?string
    {
        return self::V1_TO_V2[strtoupper(trim($v1WayCode))] ?? null;
    }
}
