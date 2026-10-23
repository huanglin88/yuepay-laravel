<?php

namespace YuePay\V2;

use Illuminate\Support\ServiceProvider;

/**
 * 粤收付 V2.0 服务提供者
 *
 * 独立注册 V2.0 全套服务，与 V1.0 的 {@see \YuePay\YuePayServiceProvider} 互不影响。
 * 已加入 composer.json 的 extra.laravel.providers，支持 Laravel 包自动发现；
 * 手动安装时也可在 config/app.php 的 providers 中单独添加本类。
 */
class V2ServiceProvider extends ServiceProvider
{
    /**
     * 注册服务
     */
    public function register(): void
    {
        // 合并 V2 配置（独立命名空间 yuepay_v2，不覆盖 V1 的 yuepay）
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/yuepay_v2.php',
            'yuepay_v2'
        );

        // V2 HTTP 客户端
        $this->app->singleton(V2Client::class, function ($app) {
            return new V2Client($app['config']->get('yuepay_v2', []));
        });

        // V2 支付服务
        $this->app->singleton(V2Service::class, function ($app) {
            return new V2Service($app->make(V2Client::class));
        });

        // V2 回调处理器
        $this->app->singleton(V2CallbackHandler::class, function ($app) {
            $config = $app['config']->get('yuepay_v2', []);

            return new V2CallbackHandler(
                $config['platform_public_key'] ?? '',
                (bool) ($config['verify_response_sign'] ?? true)
            );
        });

        // 容器别名
        $this->app->alias(V2Service::class, 'yuepay_v2');
    }

    /**
     * 引导服务
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/yuepay_v2.php' => config_path('yuepay_v2.php'),
            ], 'yuepay-v2-config');
        }
    }
}
