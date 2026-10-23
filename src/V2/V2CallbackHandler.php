<?php

namespace YuePay\V2;

use Illuminate\Http\Request;
use YuePay\Exceptions\YuePayException;
use YuePay\Support\SignHelper;

/**
 * 粤收付 V2.0 回调通知处理器
 *
 * 与 V1.0 的 {@see \YuePay\Callback\CallbackHandler} 完全独立，互不影响。
 *
 * ⚠️ 重要说明（诚实标注）：
 * 官方 2.0 发布文档【未公开】回调报文的字段结构与验签细则。本类按通用流程实现：
 *   取参（JSON 或表单）→ 平台公钥验签 → 返回原始数据。
 * 具体业务字段解析请以粤收付实际推送的报文为准（建议联调时先落日志核对），
 * 待官方文档补充后再细化。
 */
class V2CallbackHandler
{
    /** @var string */
    private $platformPublicKey;

    /** @var bool */
    private $verifySign;

    public function __construct(string $platformPublicKey, bool $verifySign = true)
    {
        $this->platformPublicKey = $platformPublicKey;
        $this->verifySign       = $verifySign;
    }

    /**
     * 解析并验证回调通知
     *
     * @param Request $request
     * @return array 验签通过后的原始回调数据
     * @throws YuePayException
     */
    public function handle(Request $request): array
    {
        $data = $request->isJson()
            ? $request->json()->all()
            : $request->all();

        if (empty($data)) {
            throw new YuePayException('回调数据为空');
        }

        if ($this->verifySign && !SignHelper::verify($data, $this->platformPublicKey)) {
            throw YuePayException::signVerifyFailed();
        }

        return $data;
    }

    /**
     * 成功响应内容（返回给平台，声明已正确接收）
     *
     * 注：官方 2.0 未明确要求的应答体，暂沿用 V1.0 约定的 success
     */
    public function successResponse(): string
    {
        return 'success';
    }

    /**
     * 失败响应内容
     */
    public function failResponse(string $message = 'fail'): string
    {
        return $message;
    }
}
