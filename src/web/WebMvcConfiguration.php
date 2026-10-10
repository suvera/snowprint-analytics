<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\web;

use dev\suvera\snowprint\infra\OperatorToken;
use dev\suvera\snowprint\web\admin\OperatorInterceptor;
use dev\suvera\snowprint\web\ui\UiHeaderInterceptor;
use dev\winterframework\core\web\config\InterceptorRegistry;
use dev\winterframework\core\web\config\WebMvcConfigurer;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Configuration;

/** Registers request interceptors. */
#[Configuration(name: 'webMvcConfigurer')]
class WebMvcConfiguration implements WebMvcConfigurer {

    /** Interceptor regexes (the registry wraps them in "/.../"). */
    public const ADMIN_PATHS = '^\/api\/admin(\/|$)';
    public const UI_PATHS = '^\/api\/(ui|share)(\/|$)';

    #[Autowired]
    private OperatorToken $operatorToken;

    public function addInterceptors(InterceptorRegistry $registry): void {
        $registry->addInterceptor(new OperatorInterceptor($this->operatorToken), self::ADMIN_PATHS);
        $registry->addInterceptor(new UiHeaderInterceptor(), self::UI_PATHS);
    }
}
