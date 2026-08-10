<?php

namespace YuePay\Support;

use YuePay\Exceptions\YuePayException;

/**
 * 粤收付 RSA2 签名/验签工具
 *
 * 签名规则（来自官方文档）：
 * 1. 将所有非空参数值按参数名 ASCII 码从小到大排序（字典序）
 * 2. 拼接成 key1=value1&key2=value2… 格式（sign 不参与签名）
 * 3. 末尾不需要添加 API 密钥，直接对 stringA 进行签名
 * 4. 使用商户 RSA 私钥进行 SHA256withRSA 签名
 * 5. 签名结果转换为 Base64 编码字符串
 */
class SignHelper
{
    /**
     * 读取密钥内容（支持文件路径或直接内容）
     */
    public static function readKey(string $key): string
    {
        if (empty($key)) {
            throw YuePayException::invalidKey('密钥内容为空');
        }

        // 如果是文件路径，读取文件内容
        if (is_file($key)) {
            $content = file_get_contents($key);
            if ($content === false) {
                throw YuePayException::invalidKey('密钥文件');
            }
            return trim($content);
        }

        return trim($key);
    }

    /**
     * 格式化密钥为 PEM 格式（统一处理私钥和公钥）
     *
     * 无论输入是纯 Base64 还是已有 PEM 头尾，都重新规范化：
     * 去除所有空白 → 按 64 字符换行 → 包装标准 PEM 头尾
     */
    private static function formatPem(string $key): string
    {
        $key = trim($key);

        // 已有 PEM 头尾：提取头尾类型，去除中间空白后重新格式化
        if (preg_match('/-----BEGIN ([A-Z ]+)-----/', $key, $headerMatches) &&
            preg_match('/-----END ([A-Z ]+)-----/', $key, $footerMatches)) {
            $header = "-----BEGIN {$headerMatches[1]}-----";
            $footer = "-----END {$footerMatches[1]}-----";

            // 取出头尾之间的内容，去除所有空白
            $start = strpos($key, $header) + strlen($header);
            $end   = strrpos($key, $footer);
            $body  = substr($key, $start, $end - $start);
            $body  = preg_replace('/\s+/', '', $body);
        } else {
            // 纯 Base64，默认类型
            $header = "-----BEGIN PRIVATE KEY-----";
            $footer = "-----END PRIVATE KEY-----";
            $body   = preg_replace('/\s+/', '', $key);
        }

        return $header . "\n" . chunk_split($body, 64, "\n") . $footer . "\n";
    }

    /**
     * 格式化私钥为 PEM 格式
     */
    public static function formatPrivateKey(string $privateKey): string
    {
        return self::formatPem(self::readKey($privateKey));
    }

    /**
     * 格式化公钥为 PEM 格式
     */
    public static function formatPublicKey(string $publicKey): string
    {
        $key = self::formatPem(self::readKey($publicKey));

        // 如果解析出来是 PRIVATE KEY 头，替换为 PUBLIC KEY
        if (strpos($key, 'PRIVATE KEY') !== false) {
            $key = str_replace(
                ['-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----'],
                ['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----'],
                $key
            );
        }

        return $key;
    }

    /**
     * 构建待签名字符串
     *
     * 规则：
     * - 参数名按 ASCII 码从小到大排序（字典序）
     * - 参数值为空的不参与签名
     * - sign 参数不参与签名
     * - 拼接为 key1=value1&key2=value2… 格式
     * - 末尾不添加 API 密钥
     */
    public static function buildSignString(array $params): string
    {
        // 移除 sign 字段
        unset($params['sign']);

        // 过滤空值
        $params = array_filter($params, function ($value) {
            return $value !== null && $value !== '' && $value !== false;
        });

        // 按 ASCII 字典序排序
        ksort($params);

        // 拼接 key=value
        $pairs = [];
        foreach ($params as $key => $value) {
            // 布尔值转为 true/false 字符串
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            // 数组/对象转为 JSON 字符串
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $pairs[] = $key . '=' . $value;
        }

        return implode('&', $pairs);
    }

    /**
     * 生成 RSA2 签名（SHA256withRSA）
     *
     * @param array  $params     待签名参数
     * @param string $privateKey 商户 RSA 私钥（PEM 格式或纯 Base64）
     * @return string Base64 编码的签名值
     * @throws YuePayException
     */
    public static function sign(array $params, string $privateKey): string
    {
        $signString = self::buildSignString($params);
        $formattedKey = self::formatPrivateKey($privateKey);

        $privateKeyResource = openssl_pkey_get_private($formattedKey);
        if ($privateKeyResource === false) {
            throw YuePayException::invalidKey('商户私钥');
        }

        $signature = '';
        $result = openssl_sign($signString, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256);

        if ($result === false) {
            $error = openssl_error_string();
            throw new YuePayException("RSA2签名失败: {$error}");
        }

        openssl_free_key($privateKeyResource);

        return base64_encode($signature);
    }

    /**
     * 验证 RSA2 签名
     *
     * @param array  $params          待验证参数（包含 sign）
     * @param string $platformPublicKey 粤收付平台 RSA 公钥（PEM 格式或纯 Base64）
     * @return bool 验签是否通过
     * @throws YuePayException
     */
    public static function verify(array $params, string $platformPublicKey): bool
    {
        if (!isset($params['sign'])) {
            return false;
        }

        $sign = $params['sign'];
        $signString = self::buildSignString($params);
        $formattedKey = self::formatPublicKey($platformPublicKey);

        $publicKeyResource = openssl_pkey_get_public($formattedKey);
        if ($publicKeyResource === false) {
            throw YuePayException::invalidKey('平台公钥');
        }

        $decodedSign = base64_decode($sign, true);
        if ($decodedSign === false) {
            openssl_free_key($publicKeyResource);
            return false;
        }

        $result = openssl_verify($signString, $decodedSign, $publicKeyResource, OPENSSL_ALGO_SHA256);
        openssl_free_key($publicKeyResource);

        return $result === 1;
    }
}
