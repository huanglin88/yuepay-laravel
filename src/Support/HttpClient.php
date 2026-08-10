<?php

namespace YuePay\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use YuePay\Exceptions\YuePayException;

/**
 * 粤收付 HTTP 请求客户端
 *
 * 职责：
 * - 发送 POST 请求（application/json）
 * - 自动注入公共参数（mchNo, appId, version, signType, reqTime）
 * - 自动签名
 * - 自动验证响应签名
 */
class HttpClient
{
    /** @var array */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * 发送 POST 请求
     *
     * @param string $uri    接口路径（如 /api/pay/unifiedOrder）
     * @param array  $params 业务参数
     * @return array 平台返回的数据（已验签）
     * @throws YuePayException
     */
    public function post(string $uri, array $params = []): array
    {
        // 注入公共参数
        $params = $this->injectCommonParams($params);

        // 签名
        $params['sign'] = SignHelper::sign($params, $this->config['private_key']);

        $url = rtrim($this->config['base_url'], '/') . '/' . ltrim($uri, '/');

        Log::channel('daily')->debug('YuePay Request', [
            'url' => $url,
            'params' => $params,
        ]);

        try {
            $response = Http::timeout($this->config['timeout'])
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($url, $params);
        } catch (\Exception $e) {
            throw YuePayException::httpError($e->getMessage());
        }

        if ($response->failed()) {
            throw YuePayException::httpError(
                "HTTP {$response->status()}: {$response->body()}"
            );
        }

        $data = $response->json();

        Log::channel('daily')->debug('YuePay Response', [
            'url' => $url,
            'response' => $data,
        ]);

        if (!is_array($data)) {
            throw new YuePayException('平台返回数据格式异常: ' . $response->body());
        }

        // 验证响应签名
        $this->verifyResponseSign($data);

        // 检查业务状态码
        $this->checkBusinessCode($data);

        return $data;
    }

    /**
     * 注入公共参数
     */
    private function injectCommonParams(array $params): array
    {
        $params['mchNo'] = $params['mchNo'] ?? $this->config['mch_no'];
        $params['appId'] = $params['appId'] ?? $this->config['app_id'];
        $params['version'] = $this->config['version'];
        $params['signType'] = $this->config['sign_type'];
        $params['reqTime'] = (string) round(microtime(true) * 1000); // 13位毫秒时间戳

        return $params;
    }

    /**
     * 验证平台响应签名
     */
    private function verifyResponseSign(array $data): void
    {
        // 如果响应中没有 sign 字段，跳过验签（部分接口可能不返回签名）
        if (!isset($data['sign'])) {
            return;
        }

        // 对 data 字段内的内容验签（平台返回结构为 { code, msg, data: {...}, sign: "..." }）
        $verifyData = $data['data'] ?? $data;

        // 如果 data 是嵌套结构，需要把 sign 放进去一起处理
        $verifyParams = is_array($verifyData) ? $verifyData : ['data' => $verifyData];
        $verifyParams['sign'] = $data['sign'];

        $verified = SignHelper::verify($verifyParams, $this->config['platform_public_key']);

        if (!$verified) {
            throw YuePayException::signVerifyFailed();
        }
    }

    /**
     * 检查业务状态码
     */
    private function checkBusinessCode(array $data): void
    {
        $code = $data['code'] ?? null;
        $msg = $data['msg'] ?? ($data['message'] ?? '未知错误');

        // code 为 0 或 "0" 或 "SUCCESS" 表示成功，不同接口可能略有差异
        $successCodes = [0, '0', 'SUCCESS', 'success'];
        if ($code !== null && !in_array($code, $successCodes, true)) {
            throw YuePayException::businessError((string) $code, (string) $msg);
        }
    }

    /**
     * 获取配置
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * 生成带签名的完整请求 URL（用于 GET/跳转类接口）
     *
     * @param string $uri    接口路径
     * @param array  $params 业务参数
     * @return string 完整URL（含签名参数）
     */
    public function buildUrl(string $uri, array $params = []): string
    {
        $params = $this->injectCommonParams($params);
        $params['sign'] = SignHelper::sign($params, $this->config['private_key']);

        $base = rtrim($this->config['base_url'], '/');
        return $base . '/' . ltrim($uri, '/') . '?' . http_build_query($params);
    }
}
