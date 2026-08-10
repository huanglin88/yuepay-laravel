# 粤收付（cnyepay）支付 SDK for Laravel

基于粤收付开放平台接口文档封装的 Laravel 支付 SDK，支持支付宝、微信等多种支付方式。

## 环境要求

- PHP >= 7.3
- Laravel >= 6.0
- ext-openssl 扩展

## 安装

### 方式一：Composer（推荐）

```bash
composer require yuepay/yuepay-laravel
```

### 方式二：手动引入

将本目录放置到 Laravel 项目的 `packages/yuepay` 下，然后在 `composer.json` 中添加：

```json
{
    "autoload": {
        "psr-4": {
            "YuePay\\": "packages/yuepay/src/"
        }
    }
}
```

执行：

```bash
composer dump-autoload
```

## 配置

### 1. 发布配置文件

```bash
php artisan vendor:publish --tag=yuepay-config
```

这会将配置文件发布到 `config/yuepay.php`。

### 2. 生成 RSA 密钥对

```bash
# 生成 RSA 私钥（PKCS#8 格式，2048位）
openssl genrsa -out private.pem 2048

# 从私钥生成公钥
openssl rsa -in private.pem -pubout -out public.pem
```

- **商户私钥**：用于生成签名，必须妥善保管，不可泄露
- **商户公钥**：提供给粤收付平台配置
- **粤收付平台公钥**：从粤收付商户后台获取，用于回调验签

### 3. 配置 .env

```env
# 粤收付配置
YUEPAY_BASE_URL=https://open.cnyepay.com
YUEPAY_MCH_NO=你的商户号
YUEPAY_APP_ID=你的应用ID
YUEPAY_PRIVATE_KEY=你的RSA私钥（可填路径或直接内容）
YUEPAY_PLATFORM_PUBLIC_KEY=粤收付平台公钥（可填路径或直接内容）
YUEPAY_NOTIFY_URL=https://your-domain.com/api/yuepay/notify
YUEPAY_DEFAULT_WAY_CODE=ALI_JSAPI
```

### 4. 注册 ServiceProvider（手动安装时需要）

在 `config/app.php` 中添加：

```php
'providers' => [
    // ...
    YuePay\YuePayServiceProvider::class,
],

'aliases' => [
    // ...
    'YuePay' => YuePay\YuePay::class,
],
```

### 5. 注册路由

在 `routes/api.php` 中添加：

```php
Route::prefix('api')->group(base_path('vendor/yuepay/routes/web.php'));
```

## 使用方法

### 基本调用

```php
use YuePay\YuePay;

// 统一下单（微信JSAPI支付）
$result = YuePay::unifiedOrder([
    'mchOrderNo' => 'ORDER' . time(),
    'wayCode'    => 'WX_JSAPI',
    'amount'     => 100,           // 单位：分（1元）
    'subject'    => '测试商品',
    'body'       => '测试商品描述',
    'openid'     => '用户的微信openid',
    'returnUrl'  => 'https://your-domain.com/return',
]);

// 统一下单（支付宝扫码支付）
$result = YuePay::unifiedOrder([
    'mchOrderNo' => 'ORDER' . time(),
    'wayCode'    => 'ALI_QR',
    'amount'     => 100,
    'subject'    => '测试商品',
]);

// 查询支付订单
$result = YuePay::queryOrder(mchOrderNo: 'ORDER123456');

// 关闭订单
$result = YuePay::closeOrder(mchOrderNo: 'ORDER123456');

// 申请退款
$result = YuePay::refund([
    'mchOrderNo'   => 'ORDER123456',
    'mchRefundNo'  => 'REFUND' . time(),
    'refundAmount' => 100,
    'refundReason' => '用户申请退款',
]);

// 查询退款
$result = YuePay::queryRefund(mchRefundNo: 'REFUND123456');
```

### 依赖注入方式

```php
use YuePay\YuePayService;

class PaymentController extends Controller
{
    public function __construct(private YuePayService $yuePay) {}

    public function pay()
    {
        $result = $this->yuePay->unifiedOrder([...]);
    }
}
```

### 处理回调通知

SDK 已内置回调处理控制器，路由为 `POST /api/yuepay/notify`。

回调处理逻辑在 `YuePay\Http\Controllers\YuePayController::notify()` 方法中，请根据业务需求修改。

关键步骤：
1. 验签（SDK自动完成）
2. 校验金额一致性
3. 更新本地订单状态
4. 返回 `success`

### 支付方式 wayCode 一览

| wayCode    | 说明                 | 必填参数           |
|------------|---------------------|-------------------|
| ALI_JSAPI | 支付宝JSAPI(小程序)  | openid            |
| ALI_QR    | 支付宝二维码         | -                 |
| ALI_BAR   | 支付宝条码(付款码)   | authCode          |
| ALI_WAP   | 支付宝H5            | -                 |
| ALI_APP   | 支付宝APP           | -                 |
| WX_JSAPI  | 微信JSAPI(小程序)    | openid            |
| WX_NATIVE | 微信扫码             | -                 |
| WX_BAR    | 微信条码(付款码)     | authCode          |
| WX_H5     | 微信H5              | -                 |
| WX_APP    | 微信APP             | -                 |

## 目录结构

```
yuepay-sdk/
├── config/
│   └── yuepay.php              # 配置文件
├── src/
│   ├── YuePayServiceProvider.php  # 服务提供者
│   ├── YuePay.php                # Facade 门面
│   ├── YuePayService.php         # 核心支付服务
│   ├── Support/
│   │   ├── SignHelper.php        # RSA2签名/验签工具
│   │   └── HttpClient.php        # HTTP请求客户端
│   ├── Callback/
│   │   └── CallbackHandler.php   # 回调通知处理器
│   ├── Exceptions/
│   │   └── YuePayException.php   # 异常类
│   └── Http/
│       └── Controllers/
│           └── YuePayController.php  # 示例控制器
├── routes/
│   └── web.php                  # 路由文件
├── keys/                        # RSA密钥存放目录
├── composer.json
└── README.md
```

## 签名说明

SDK 使用 RSA2（SHA256withRSA）签名算法，流程如下：

1. 将所有非空参数按参数名 ASCII 码字典序排序
2. 拼接为 `key1=value1&key2=value2...` 格式（sign 不参与签名）
3. 使用商户 RSA 私钥进行 SHA256withRSA 签名
4. 签名结果转换为 Base64 编码

> **注意**：与微信支付不同，粤收付 RSA2 签名时拼接字符串末尾**不需要**添加 API 密钥。

## 常见问题

### Q: 签名失败？

- 检查私钥格式是否为 PKCS#8
- 确认私钥是否包含完整头尾标识（`-----BEGIN PRIVATE KEY-----`）
- 支持纯 Base64 字符串（SDK会自动格式化）

### Q: 验签失败？

- 确认使用的是粤收付**平台公钥**（不是商户公钥）
- 检查平台公钥是否正确获取

### Q: 回调收不到通知？

- 确认 `notifyUrl` 为公网可访问的 HTTPS 地址
- 检查服务器防火墙是否放行
- 回调路由需排除 CSRF 中间件（SDK已处理）

## License

MIT
