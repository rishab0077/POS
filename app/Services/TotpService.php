<?php

namespace App\Services;

use Illuminate\Support\Str;

class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    public function provisioningUri(string $secret, string $accountName): string
    {
        $issuer = (string) config('security.mfa.issuer', config('app.name'));
        $label = rawurlencode($issuer . ':' . $accountName);

        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => (int) config('security.mfa.digits', 6),
            'period' => (int) config('security.mfa.period', 30),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\D/', '', $code);
        $digits = (int) config('security.mfa.digits', 6);

        if (strlen($code) !== $digits) {
            return false;
        }

        $timestamp ??= time();
        $period = (int) config('security.mfa.period', 30);
        $counter = intdiv($timestamp, $period);

        foreach ([-1, 0, 1] as $offset) {
            if (hash_equals($this->codeAtCounter($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public function currentCode(string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return $this->codeAtCounter(
            $secret,
            intdiv($timestamp, (int) config('security.mfa.period', 30)),
        );
    }

    public function generateRecoveryCodes(?int $count = null): array
    {
        $count ??= (int) config('security.mfa.recovery_codes', 8);

        return collect(range(1, $count))
            ->map(fn () => Str::upper(Str::random(5) . '-' . Str::random(5)))
            ->all();
    }

    private function codeAtCounter(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        $high = intdiv($counter, 4294967296);
        $low = $counter % 4294967296;
        $hash = hash_hmac('sha1', pack('N2', $high, $low), $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $binary = (
            ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff)
        );
        $digits = (int) config('security.mfa.digits', 6);

        return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $value): string
    {
        $bits = '';

        foreach (str_split($value) as $character) {
            $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $encoded;
    }

    private function base32Decode(string $value): string
    {
        $value = strtoupper(preg_replace('/[^A-Z2-7]/', '', $value));
        $bits = '';

        foreach (str_split($value) as $character) {
            $position = strpos(self::ALPHABET, $character);

            if ($position === false) {
                return '';
            }

            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }

        return $decoded;
    }
}
