<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;

class ExchangeSecretVault
{
    public function encrypt(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        return Crypt::encryptString($value);
    }

    public function decrypt(?string $cipher): ?string
    {
        if (!$cipher) {
            return null;
        }

        try {
            return Crypt::decryptString($cipher);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function mask(?string $cipher): ?string
    {
        $plain = $this->decrypt($cipher);
        if (!$plain) {
            return null;
        }

        $tail = substr($plain, -4);
        return '••••' . $tail;
    }

    public function rotate(?string $cipher): ?string
    {
        $plain = $this->decrypt($cipher);
        return $plain ? $this->encrypt($plain) : null;
    }
}
