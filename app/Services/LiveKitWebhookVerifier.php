<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * التحقق من صحة توقيع webhooks الخاصة بـ LiveKit.
 *
 * LiveKit يرسل كل webhook موقّع بـ JWT (HS256) في هيدر:
 *     Authorization: Bearer <jwt>
 *
 * الـ JWT يحتوي على claims مهمة:
 *   - iss     = الـ API key
 *   - sub     = اسم الغرفة
 *   - exp/nbf = صلاحية التوكن
 *   - sha256  = base64(HMAC-SHA256(api_secret, raw_body))
 *
 * التحقق يتكوّن من خطوتين:
 *   1) صحة توقيع الـ JWT نفسه (HS256 بالـ API secret).
 *   2) تطابق بصمة الـ body (sha256 claim) مع الـ raw body الفعلي.
 *
 * بدون التوقيع، أي حد يقدر يبعت POST ويمسك جلسة أو ينهيها.
 */
class LiveKitWebhookVerifier
{
    /**
     * سماح بسيط لتأخّر الساعة (ثواني).
     */
    protected const CLOCK_SKEW_SECONDS = 30;

    /**
     * نتيجة التحقق: [ok, reason, claims]
     *
     * @return array{0: bool, 1: string, 2: array<string, mixed>}
     */
    public function verify(string $rawBody, ?string $authorizationHeader): array
    {
        $apiKey = (string) config('services.livekit.api_key', '');
        $apiSecret = (string) config('services.livekit.api_secret', '');

        if ($apiKey === '' || $apiSecret === '') {
            // غير مُعدّ: في الإنتاج نرفض (أمان)، محلياً نسمح مع تحذير.
            if (app()->environment('production')) {
                Log::error('LiveKit webhook received but LIVEKIT_API_KEY/SECRET are not configured.');

                return [false, 'livekit_not_configured', []];
            }
            Log::warning('LiveKit webhook skipped verification (keys not configured, non-production env).');

            return [true, 'skipped_not_configured', []];
        }

        $token = $this->bearerToken($authorizationHeader);
        if ($token === '') {
            return [false, 'missing_authorization_header', []];
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return [false, 'malformed_token', []];
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = json_decode($this->base64UrlDecode($encodedHeader), true);
        $claims = json_decode($this->base64UrlDecode($encodedPayload), true);

        if (! is_array($header) || ! is_array($claims)) {
            return [false, 'malformed_token_payload', []];
        }

        // الخوارزمية المسموحة فقط (HS256) — لمنع alg=none أو RS256 confusion.
        $alg = $header['alg'] ?? '';
        if ($alg !== 'HS256') {
            return [false, 'unsupported_algorithm', []];
        }

        // 1) صحة توقيع الـ JWT
        $expectedSignature = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $apiSecret, true);
        $actualSignature = $this->base64UrlDecode($encodedSignature);
        if (! hash_equals($expectedSignature, $actualSignature)) {
            return [false, 'invalid_jwt_signature', []];
        }

        // 2) الـ API key مطابق
        $iss = (string) ($claims['iss'] ?? '');
        if (! hash_equals($apiKey, $iss)) {
            return [false, 'issuer_mismatch', $claims];
        }

        // 3) صلاحية التوكن
        $now = time();
        $exp = (int) ($claims['exp'] ?? 0);
        if ($exp > 0 && $now > $exp + self::CLOCK_SKEW_SECONDS) {
            return [false, 'token_expired', $claims];
        }
        $nbf = (int) ($claims['nbf'] ?? 0);
        if ($nbf > 0 && $now + self::CLOCK_SKEW_SECONDS < $nbf) {
            return [false, 'token_not_yet_valid', $claims];
        }

        // 4) بصمة الـ body
        $bodyHash = $claims['sha256'] ?? null;
        if (! is_string($bodyHash) || $bodyHash === '') {
            return [false, 'missing_body_hash', $claims];
        }
        $expectedHash = base64_encode(hash_hmac('sha256', $rawBody, $apiSecret, true));
        if (! hash_equals($expectedHash, $bodyHash)) {
            return [false, 'body_hash_mismatch', $claims];
        }

        return [true, 'ok', $claims];
    }

    /**
     * يستخرج التوكن من هيدر Authorization.
     */
    protected function bearerToken(?string $header): string
    {
        if ($header === null) {
            return '';
        }
        $header = trim($header);
        if (stripos($header, 'Bearer ') === 0) {
            $header = substr($header, 7);
        }

        return trim($header);
    }

    /**
     * base64url decode متوافق مع JWT (بدون padding).
     */
    protected function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
