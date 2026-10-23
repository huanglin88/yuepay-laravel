# 粤收付 V2.0（开发平台 2.0）接入说明

本 SDK 在原有 **V1.0** 能力之外，新增 **V2.0（粤收付开发平台 2.0）** 接入。

两套接口**完全独立、可同时使用、互不冲突**：

| | V1.0 | V2.0（本文档） |
|---|---|---|
| 门面 | `YuePay` | `YuePayV2`（`YuePay\V2\V2Facade`） |
| 服务类 | `YuePay\YuePayService` | `YuePay\V2\V2Service` |
| 配置键 | `config/yuepay.php` → `yuepay` | `config/yuepay_v2.php` → `yuepay_v2` |
| 网关/前缀 | `/api/...` | `https://pay.cnyepay.com` + `/api/v2/...` |
| 请求信封 | `mchNo/appId/reqTime/version/signType` | `mchNo/orgNo/orderDate`（**无** reqTime/version/signType） |
| wayCode | `ALI_*` / `WX_*` / `YSF_*` | `A_*` / `T_*` / `U_*` |
| 渠道参数 | 扁平 `channelExtra` | 嵌套对象 `alipayData` / `wxpayData` / `unionpayData` |

> V1.0 的所有用法保持不变，本文件仅说明 V2.0 的增量能力。

---

## 1. 目录结构（新增部分）

```
config/
└── yuepay_v2.php                     # V2.0 配置（与 yuepay.php 独立）
routes/
└── v2.php                            # V2.0 路由（前缀 yuepay/v2）
src/V2/
├── WayCode.php                       # wayCode 枚举 + 渠道解析 + V1→V2 映射
├── V2Client.php                      # 信封注入 / RSA2 签名 / 响应验签
├── V2Service.php                     # V2.0 全部接口
├── V2CallbackHandler.php             # 回调验签
├── V2Facade.php                      # 门面 YuePayV2
├── V2ServiceProvider.php             # 服务注册
└── Http/Controllers/V2Controller.php # 示例控制器
```

## 2. 安装与注册

### 2.1 Composer 自动发现（推荐）

`composer.json` 已包含：

```json
"extra": {
    "laravel": {
        "providers": ["YuePay\\YuePayServiceProvider", "YuePay\\V2\\V2ServiceProvider"],
        "aliases":   { "YuePay": "YuePay\\YuePay", "YuePayV2": "YuePay\\V2\\V2Facade" }
    }
}
```

`composer require` 后无需额外配置。手动引入（packages 方式）时，请在 `config/app.php` 中补：

```php
'providers' => [
    YuePay\YuePayServiceProvider::class,
    YuePay\V2\V2ServiceProvider::class,   // 新增
],
'aliases' => [
    'YuePay'   => YuePay\YuePay::class,
    'YuePayV2' => YuePay\V2\V2Facade::class, // 新增
],
```

### 2.2 发布配置（可选）

```bash
php artisan vendor:publish --tag=yuepay-v2-config
```

## 3. 配置

V2.0 使用**独立配置命名空间** `yuepay_v2`，不会覆盖 V1.0 的 `yuepay`。

```env
# ---- V2.0 ----
YUEPAY_V2_BASE_URL=https://pay.cnyepay.com
YUEPAY_V2_MCH_NO=你的商户号
YUEPAY_V2_ORG_NO=你的机构号             # V2.0 必填（商户调用=商户号）
YUEPAY_V2_APP_ID=你的应用AppID          # 选填
YUEPAY_V2_PRIVATE_KEY=商户RSA私钥       # 可填路径或内容；不填则回落到 YUEPAY_PRIVATE_KEY
YUEPAY_V2_PLATFORM_PUBLIC_KEY=平台公钥  # 不填则回落到 YUEPAY_PLATFORM_PUBLIC_KEY
YUEPAY_V2_NOTIFY_URL=https://your-domain.com/api/yuepay/v2/notify
YUEPAY_V2_DEFAULT_WAY_CODE=A_NATIVE
# 联调排查开关
YUEPAY_V2_VERIFY_SIGN=true
YUEPAY_V2_APPID_FIELD=appid             # 或 appId（见第 10 节）
YUEPAY_V2_EXPIRE_TIME_FIELD=expireTime  # 或 expiredTime（见第 10 节）
```

## 4. 快速上手

### 4.1 门面方式

```php
use YuePay\V2\V2Facade as YuePayV2;

$res = YuePayV2::pay([
    'mchOrderNo' => 'ORDER' . time(),
    'amount'     => 100,            // 单位：分
    'subject'    => '测试商品',
    'wayCode'    => 'A_JSAPI',      // V2.0：A_ / T_ / U_ 前缀
    'buyerUserId'=> '2088xxxx',     // 或传 'alipayData' => ['buyerUserId' => '...']
]);
```

### 4.2 依赖注入

```php
use YuePay\V2\V2Service;

class PayController extends Controller
{
    public function __construct(private V2Service $yuePayV2) {}

    public function pay()
    {
        return $this->yuePayV2->pay([...]);
    }
}
```

## 5. 统一下单

`pay()` 会按 `wayCode` 自动路由到对应渠道接口：

| wayCode 前缀 | 渠道 | 实际请求路径 |
|---|---|---|
| `A_` | 支付宝 | `POST /api/v2/pay/alipay/order` |
| `T_` | 微信 | `POST /api/v2/pay/wechat/order` |
| `U_` | 银联 | `POST /api/v2/pay/uniQrcode/order` |

也可显式调用：`wechatOrder()` / `alipayOrder()` / `unionpayOrder()`。

### 5.1 公共参数

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `mchOrderNo` | string | ✅ | 商户订单号 |
| `amount` | int | ✅ | 金额，单位**分** |
| `subject` | string | ✅ | 商品标题 |
| `wayCode` | string | ✅ | V2.0 支付方式，缺省取配置 `default_way_code` |
| `body` | string | | 商品描述，缺省同 `subject` |
| `clientIp` | string | | 客户端 IP，缺省自动获取 |
| `notifyUrl` | string | | 异步通知地址，缺省取配置 |
| `extParam` | string | | 商户扩展参数，回调原样返回 |
| `appid` | string | | 应用 AppID（字段名可配，见第 10 节） |
| `expireTime` | int | | 订单过期秒数（≤7200） |
| `delayedFlag` | string | | 延迟入账 `0`/`1` |
| `preOrderType` | string | | **拉起类型**，见 5.2 |
| `returnUrl` | string | | 前台跳转地址 |

### 5.2 preOrderType（App / 小程序拉起）

与 V1.0 统一下单保持一致：**传了就透传**，可用于统一下单或收银台预下单。

```php
$res = YuePayV2::pay([
    'mchOrderNo'   => 'ORDER' . time(),
    'amount'       => 100,
    'subject'      => '测试商品',
    'wayCode'      => 'A_MINIAPP',
    'buyerUserId'  => '2088xxxx',
    'preOrderType' => 'A_APP_MINI',   // 支付宝小程序拉起
]);
```

取值：`T_APP_MINI`（微信小程序）/ `T_EMBED_MINI`（微信半屏小程序）/ `A_APP_MINI`（支付宝小程序）。

> 注意：`preOrderType` 未列入官方下单接口 schema，属可选透传字段（V1.0 同样如此）。若网关对未知字段严格校验，联调时请确认。

### 5.3 渠道数据对象

渠道专有参数有两种传法（二选一）：

```php
// 写法一：扁平参数（SDK 自动收进对应对象）
['wayCode' => 'T_JSAPI', 'openid' => 'oXxx', 'subAppId' => 'wx123']

// 写法二：直接传嵌套对象
['wayCode' => 'T_JSAPI', 'wxpayData' => ['openid' => 'oXxx', 'subAppId' => 'wx123']]
```

| 对象 | 可用字段 |
|---|---|
| `alipayData` | `authCode`、`payDataType`、`buyerUserId` |
| `wxpayData` | `authCode`、`openid`、`subAppId`、`payDataType` |
| `unionpayData` | `frontUrl`、`payDataType`、`userId`、`authCode` |

签名时该对象会**整体序列化为紧凑 JSON**（如 `alipayData={"buyerUserId":"2088..."}`）参与排序，无需手工拼接。

## 6. 收银台预下单

```php
$res = YuePayV2::preOrder([
    'mchOrderNo'   => 'ORDER' . time(),
    'amount'       => 100,
    'subject'      => '测试商品',
    'preOrderType' => 'T_APP_MINI',   // 必填
]);
```

路径：`POST /api/v2/pay/common/preOrder`

## 7. 查询 / 关单 / 退款

```php
// 查单（payOrderId 与 mchOrderNo 二选一）
YuePayV2::queryOrder(mchOrderNo: 'ORDER123');
YuePayV2::queryOrder(payOrderId: 'P2105...', orderDate: '2026-10-23');

// 关单
YuePayV2::closeOrder(mchOrderNo: 'ORDER123');

// 退款（orderDate 传【原订单】下单日期，跨日退款务必传）
YuePayV2::refund([
    'mchOrderNo'   => 'ORDER123',
    'mchRefundNo'  => 'REFUND' . time(),
    'refundAmount' => 100,
    'refundReason' => '用户申请退款',
    'orderDate'    => '2026-10-23',
]);

// 查退款
YuePayV2::queryRefund(mchRefundNo: 'REFUND123');
```

| 操作 | 路径 |
|---|---|
| 查询支付订单 | `POST /api/v2/pay/query` |
| 关闭订单 | `POST /api/v2/pay/close` |
| 统一退款 | `POST /api/v2/refund/refundOrder` |
| 查询退款订单 | `POST /api/v2/pay/query`（复用查单路径） |

## 8. 分账 / 分账回退

```php
// 请求分账
YuePayV2::divisionApply([
    'mchOrderNo'          => 'ORDER123',
    'channelBatchNo'      => 'BATCH' . time(),
    'transSumAmt'         => 100,
    'transSumCount'       => 1,
    'separateOrderDetail' => [
        ['receiverId' => 'R001', 'sepaTransAmount' => 100],
    ],
    'notifyUrl' => 'https://your-domain.com/api/yuepay/v2/notify',
]);

// 查询分账
YuePayV2::divisionQuery(['channelBatchNo' => 'BATCH123']);

// 发起分账回退（orderDate 为【原分账请求】日期）
YuePayV2::splitRefund([
    'channelBatchNo'     => 'BATCH123',
    'splitRefundBatchNo' => 'SR' . time(),
    'refundAmountSum'    => 100,
    'separateRefundList' => [
        ['receiverId' => 'R001', 'refundAmount' => 100],
    ],
    'orderDate' => '2026-10-23',
]);

// 分账回退查询
YuePayV2::splitRefundQuery(['channelBatchNo' => 'BATCH123']);
```

## 9. 获取渠道用户ID / 回调 / 路由

```php
$url = YuePayV2::channelUserIdUrl([
    'ifCode'      => 'AUTO',
    'redirectUrl' => 'https://your-domain.com/callback',
]);
// 直接 redirect($url)
```

回调：`V2CallbackHandler` 负责取参与验签，示例路由为 `POST /api/yuepay/v2/notify`。

路由引入（`routes/api.php`）：

```php
Route::prefix('api')->group(base_path('vendor/yuepay/routes/v2.php'));
```

| 方法 | 路径 |
|---|---|
| POST | `/api/yuepay/v2/order` |
| POST | `/api/yuepay/v2/pre-order` |
| GET | `/api/yuepay/v2/query` |
| POST | `/api/yuepay/v2/close` |
| POST | `/api/yuepay/v2/refund` |
| GET | `/api/yuepay/v2/refund/query` |
| POST | `/api/yuepay/v2/division/apply` |
| POST | `/api/yuepay/v2/division/query` |
| POST | `/api/yuepay/v2/split/refund` |
| GET | `/api/yuepay/v2/channel-user-id/url` |
| POST | `/api/yuepay/v2/notify` |

V1.0 路由前缀为 `yuepay`，V2.0 为 `yuepay/v2`，二者不冲突。

## 10. ⚠️ 待官方确认项（官方文档内部不一致）

官方 2.0 文档在以下字段上存在不一致或未公开，SDK 已做成**配置可切换**，联调时按网关实际要求调整即可：

| 项目 | 官方 schema | 签名示例页 | SDK 处理 |
|---|---|---|---|
| AppID 字段名 | `appid` | `appId` | 配置 `appid_field`，默认 `appid` |
| 订单过期字段名 | `expireTime` | `expiredTime` | 配置 `expire_time_field`，默认 `expireTime` |
| `extParam` 必填性 | 标 required | 示例中缺失 | 传了才带 |
| 错误码 `code` 枚举 | 未给出 | — | 配置 `success_codes`，默认 `0/SUCCESS` |
| 订单 `state` 枚举 | 未给出 | — | 以实际返回为准 |
| 回调报文结构 | 未公开 | — | `V2CallbackHandler` 仅做取参 + 验签，字段解析按实际报文处理 |
| 沙箱域名 | 未单列 | — | 向粤收付获取后填 `YUEPAY_V2_BASE_URL` |

## 11. 常见问题

**Q：V2.0 签名和 V1.0 有什么不同？**
算法相同（RSA2 = SHA256withRSA + Base64，末尾不加密钥）。区别在**信封**（`orgNo`+`orderDate` 取代 `reqTime`/`version`/`signType`）和**渠道参数嵌套**（`alipayData` 等对象整体 JSON 化参与签名）。

**Q：会影响现有 V1.0 吗？**
不会。V2.0 全部代码在 `src/V2/`，配置在 `yuepay_v2`，路由前缀 `yuepay/v2`，V1.0 源码零改动。

**Q：验签一直失败？**
先用 `YUEPAY_V2_VERIFY_SIGN=false` 关闭响应验签，确认是否签名问题；再检查平台公钥是否与当前环境匹配（沙箱/生产不可混用）。

**Q：`orderDate` 总是传今天吗？**
不是。下单/查单用当天；**退款**要传**原支付订单**的下单日期；**分账回退**要传**原分账请求**日期，否则可能查不到单。

## 12. 自检

不启动 Laravel 即可验证签名与信封逻辑：参见仓库外自检脚本，覆盖「待签串与官方签名示例逐字一致」「信封注入」「V1 字段剔除」「签名验签往返」「wayCode 渠道解析」等 17 项断言。
