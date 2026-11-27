<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * 加密强制校验（Guard）。
 *
 * 命中 decrypt_routes 的请求必须携带 v2/v3 密文，否则返回 400。
 */
final readonly class ReqResEnforceEncrypted
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! $this->shouldEnforce($request)) {
            return $next($request);
        }

        $content = $request->getContent();

        if ($content === '' || $content === null) {
            if ((bool) config('req-res-crypto.enforce.allow_empty_body', true)) {
                return $next($request);
            }

            return $this->reject();
        }

        $binary = base64_decode($content, true);

        if ($binary === false || $binary === '' || ! in_array(ord($binary[0]), [2, 3], true)) {
            return $this->reject();
        }

        return $next($request);
    }

    private function shouldEnforce(Request $request): bool
    {
        if (! (bool) config('req-res-crypto.enforce.enabled', true)) {
            return false;
        }

        foreach ((array) config('req-res-crypto.decrypt_routes', ['api/*']) as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }

    private function reject(): mixed
    {
        return response()->json([
            'code' => 'REQ_RES_CRYPTO_PLAINTEXT_FORBIDDEN',
            'message' => '该接口仅接受加密请求',
        ], 400);
    }
}
