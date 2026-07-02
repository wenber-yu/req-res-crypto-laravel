<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\Attributes;

use Attribute;

/**
 * 跳过加解密处理。
 *
 * 当全局中间件对所有路由加密时，用此属性标记不需要加解密的路由。
 *
 * @example
 *   #[SkipReqResCrypto]
 *   class HealthController { ... }
 *
 *   class ApiController {
 *       #[SkipReqResCrypto]
 *       public function publicEndpoint(): array { ... }
 *   }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class SkipReqResCrypto
{
}
