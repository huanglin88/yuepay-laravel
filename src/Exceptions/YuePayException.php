<?php

namespace YuePay\Exceptions;

use Exception;

class YuePayException extends Exception
{
    /**
     * API返回的业务错误
     */
    public static function businessError(string $code, string $msg): self
    {
        return new self("[{$code}] {$msg}");
    }

    /**
     * 签名验证失败
     */
    public static function signVerifyFailed(): self
    {
        return new self('签名验证失败，数据可能被篡改');
    }

    /**
     * 密钥格式错误
     */
    public static function invalidKey(string $type): self
    {
        return new self("{$type}格式错误，请检查是否为有效的PEM格式密钥");
    }

    /**
     * HTTP请求失败
     */
    public static function httpError(string $message): self
    {
        return new self("HTTP请求失败: {$message}");
    }
}
