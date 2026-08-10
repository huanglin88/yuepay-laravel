<?php

namespace YuePay;

use Illuminate\Support\ServiceProvider;
use YuePay\Callback\CallbackHandler;
use YuePay\Support\HttpClient;

/**
 * 粤收付支付 Laravel 服务提供者
 */
class YuePayServiceProvider extends ServiceProvider
{
    /**
     * 注册服务
     */
    public function register(): void
    {
        // 合并配置
        $this->mergeConfigFrom(
            __DIR__ . '/../config/yuepay.php',
            'yuepay'
        );

        // 注册 HttpClient
        $this->app->singleton(HttpClient::class, function ($app) {
            $config = $app['config']->get('yuepay');
            return new HttpClient($config);
        });

        // 注册 YuePayService
        $this->app->singleton(YuePayService::class, function ($app) {
            return new YuePayService($app->make(HttpClient::class));
        });

        // 注册 CallbackHandler
        $this->app->singleton(CallbackHandler::class, function ($app) {
            $config = $app['config']->get('yuepay');
            return new CallbackHandler($config['platform_public_key']);
        });

        // 注册 Facade 别名
        $this->app->alias(YuePayService::class, 'yuepay');
    }

    /**
     * 引导服务
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // 发布配置文件
            $this->publishes([
                __DIR__ . '/../config/yuepay.php' => config_path('yuepay.php'),
            ], 'yuepay-config');
        }
    }
}
