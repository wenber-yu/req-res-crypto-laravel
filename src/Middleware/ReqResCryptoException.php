<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Wenbo\ReqResCrypto\Core\Exceptions\CryptoException;
use Wenbo\ReqResCrypto\Core\Exceptions\KeyException;
use Wenbo\ReqResCrypto\Core\Exceptions\ReplayException;
use Wenbo\ReqResCrypto\Core\Exceptions\SessionException;
use Wenbo\ReqResCrypto\Core\Exceptions\SignatureException;

/**
 * 把 core 的加解密异常统一转换为稳定的 HTTP 状态码与业务错误码。
 *
 * 建议放在中间件链最外层，这样 Guard、解密、加密抛出的异常都能被归一。
 */
final readonly class ReqResCryptoException
{
    public function handle(Request $request, Closure $next): mixed
    {
        try {
            return $next($request);
        } catch (CryptoException $e) {
            if (! (bool) config('req-res-crypto.exceptions.enabled', true)) {
                throw $e;
            }

            [$status, $code] = $this->map($e);

            $response = response()->json([
                'code' => $code,
                'message' => $e->getMessage(),
            ], $status)->header('X-Req-Res-Crypto-Error', $code);

            if ($e instanceof SessionException) {
                $response->header('X-Req-Res-Crypto-Session-Expired', '1');
            }

            return $response;
        }
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function map(CryptoException $e): array
    {
        return match (true) {
            $e instanceof SessionException => [409, SessionException::CODE_EXPIRED],
            $e instanceof ReplayException => [409, 'REQ_RES_CRYPTO_REPLAY'],
            $e instanceof KeyException => [503, 'REQ_RES_CRYPTO_KEY_UNAVAILABLE'],
            $e instanceof SignatureException => [500, 'REQ_RES_CRYPTO_SIGNATURE_INVALID'],
            default => [400, 'REQ_RES_CRYPTO_BAD_REQUEST'],
        };
    }
}
