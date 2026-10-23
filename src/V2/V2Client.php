<?php

namespace YuePay\V2;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use YuePay\Exceptions\YuePayException;
use YuePay\Support\SignHelper;

/**
 * 粤收付 V2.0（开发平台 2.0）HTTP 请求客户端
 *
 * 与 V1.0 的 {@see \YuePay\Support\HttpClient} 完全独立，职责：
 * - 注入 V2.0 请求信封：mchNo、orgNo、orderDate（yyyy-MM-dd）、可选 appid
 *   ★ 与 V1.0 的差异：不注入 reqTime(13位毫秒) / version / signType
 * - RSA2（SHA256withRSA）签名
 * - 校验平台响应签名（平台公钥）
 * - 校验业务状态码
 *
 * 签名原语复用 {@see \YuePay\Support\SignHelper}：其已对数组/对象做紧凑 JSON 序列化，
 * 正好满足 V2.0「渠道数据对象整体 JSON 化参与签名」的要求，因此无需重复实现加密逻辑。
 */
class V2Client
{
    /** @var array */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * 发送 POST 请求（application/json）
     *
     * @param string $uri    V2.0 接口路径（如 /api/v2/pay/alipay/order）
     * @param array  $params 业务参数
     * @return array 平台返回数据（已验签）
     * @throws YuePayException
     */
    public function post(string $uri, array $params = []): array
    {
        $payload = $this->preparePayload($params);
        $url     = $this->buildEndpoint($uri);

        Log::channel('daily')->debug('YuePay V2 Request', [
            'url'    => $url,
            'params' => $payload,
        ]);

        try {
            $response = Http::timeout($this->config['timeout'] ?? 30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ])
                ->post($url, $payload);
        } catch (\Exception $e) {
            throw YuePayException::httpError($e->getMessage());
        }

        if ($response->failed()) {
            throw YuePayException::httpError(
                "HTTP {$response->status()}: {$response->body()}"
            );
        }

        $data = $response->json();

        Log::channel('daily')->debug('YuePay V2 Response', [
            'url'      => $url,
            'response' => $data,
        ]);

        if (!is_array($data)) {
            throw new YuePayException('平台返回数据格式异常: ' . $response->body());
        }

        $this->verifyResponseSign($data);
        $this->checkBusinessCode($data);

        return $data;
    }

    /**
     * 组装并签名请求参数（纯逻辑，不发起 HTTP，便于单元测试/排查）
     *
     * @param array $params 业务参数
     * @return array 已注入信封并签名的完整请求体
     * @throws YuePayException
     */
    public function preparePayload(array $params): array
    {
        // 1) 注入 V2.0 信封
        $params['mchNo']     = $params['mchNo'] ?? ($this->config['mch_no'] ?? '');
        $params['orgNo']     = $params['orgNo'] ?? ($this->config['org_no'] ?? '');
        $params['orderDate'] = $params['orderDate'] ?? date('Y-m-d');

        $appidField = $this->config['appid_field'] ?? 'appid';
        if (!isset($params[$appidField]) && !empty($this->config['appid'])) {
            $params[$appidField] = $this->config['appid'];
        }

        // 2) 明确剔除 V1.0 遗留字段（V2.0 已不使用，避免误传影响签名）
        unset($params['reqTime'], $params['version'], $params['signType']);

        // 3) 过滤空值（保留 0；嵌套空对象另行由调用方控制）
        $params = array_filter($params, function ($value) {
            return $value !== null && $value !== '';
        });

        // 4) RSA2 签名（嵌套数组会被 SignHelper 紧凑 JSON 化后参与排序拼接）
        $params['sign'] = SignHelper::sign($params, $this->config['private_key'] ?? '');

        return $params;
    }

    /**
     * 生成带签名的完整请求 URL（用于 GET/跳转类接口，如 channelUserId/jump）
     *
     * @param string $uri    V2.0 接口路径
     * @param array  $params 业务参数
     * @return string
     * @throws YuePayException
     */
    public function buildUrl(string $uri, array $params = []): string
    {
        $payload = $this->preparePayload($params);

        return $this->buildEndpoint($uri) . '?' . http_build_query($payload);
    }

    /**
     * 拼接完整接口地址
     */
    private function buildEndpoint(string $uri): string
    {
        return rtrim($this->config['base_url'] ?? 'https://pay.cnyepay.com', '/')
            . '/' . ltrim($uri, '/');
    }

    /**
     * 校验平台响应签名
     *
     * V2.0 统一响应结构：{ code, msg, sign, data }
     * 注意：官方发布文档未明确 sign 的待签串构成，此处与 V1.0 保持一致（对 data 内容验签），
     * 可通过配置 verify_response_sign=false 临时关闭以排查。
     */
    private function verifyResponseSign(array $data): void
    {
        if (empty($this->config['verify_response_sign'])) {
            return;
        }

        if (!isset($data['sign'])) {
            return;
        }

        $verifyData   = $data['data'] ?? $data;
        $verifyParams = is_array($verifyData) ? $verifyData : ['data' => $verifyData];
        $verifyParams['sign'] = $data['sign'];

        if (!SignHelper::verify($verifyParams, $this->config['platform_public_key'] ?? '')) {
            throw YuePayException::signVerifyFailed();
        }
    }

    /**
     * 校验业务状态码
     */
    private function checkBusinessCode(array $data): void
    {
        $code = $data['code'] ?? null;
        $msg  = $data['msg'] ?? ($data['message'] ?? '未知错误');

        if ($code === null) {
            return;
        }

        $successCodes = $this->config['success_codes'] ?? [0, '0', 'SUCCESS', 'success'];

        foreach ($successCodes as $successCode) {
            if ((string) $successCode === (string) $code) {
                return;
            }
        }

        throw YuePayException::businessError((string) $code, (string) $msg);
    }

    /**
     * 获取配置
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}
