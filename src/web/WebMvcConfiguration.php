<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\web;

use dev\suvera\snowprint\infra\OperatorToken;
use dev\suvera\snowprint\web\admin\OperatorInterceptor;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\web\config\InterceptorRegistry;
use dev\winterframework\core\web\config\WebMvcConfigurer;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Configuration;

/**
 * Registers request interceptors (beans are looked up on
 * first request, not here, so nothing touches the database before fork).
 */
#[Configuration(name: 'webMvcConfigurer')]
class WebMvcConfiguration implements WebMvcConfigurer {

    /** Interceptor regex (the registry wraps it in "/.../"). */
    public const ADMIN_PATHS = '^\/api\/admin(\/|$)';

    #[Autowired]
    private ApplicationContext $ctx;

    public function addInterceptors(InterceptorRegistry $registry): void {
        $ctx = $this->ctx;
        $registry->addInterceptor(
            new OperatorInterceptor(static fn(): OperatorToken => $ctx->beanByClass(OperatorToken::class)),
            self::ADMIN_PATHS
        );
    }
}
