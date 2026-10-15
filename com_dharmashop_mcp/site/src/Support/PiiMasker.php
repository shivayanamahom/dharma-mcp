<?php

/**
 * Dharma Shop MCP.
 *
 * @package    com_dharmashop_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaShopMcp\Site\Support;

\defined('_JEXEC') or die;

final class PiiMasker
{
    private const PII_KEYS = [
        'email',
        'customer_email',
        'phone',
        'telephone',
        'customer_phone',
        'mobile',
        'address',
        'customer_address',
        'delivery_address',
        'street',
        'house',
        'flat',
        'postcode',
        'zip',
        'passport',
        'inn',
    ];

    public static function maskEmail(?string $email): string
    {
        if (empty($email)) {
            return '';
        }

        $parts = explode('@', trim($email), 2);
        if (count($parts) !== 2) {
            return '***';
        }

        $name = $parts[0];
        $domain = $parts[1];

        $len = mb_strlen($name);
        if ($len <= 2) {
            $maskedName = mb_substr($name, 0, 1) . '***';
        } else {
            $maskedName = mb_substr($name, 0, 1) . '***' . mb_substr($name, -1);
        }

        return $maskedName . '@' . $domain;
    }

    public static function maskPhone(?string $phone): string
    {
        if (empty($phone)) {
            return '';
        }

        $cleaned = preg_replace('/[^\d+]/', '', trim($phone)) ?? '';
        $len = strlen($cleaned);

        if ($len < 7) {
            return '***';
        }

        $prefix = substr($cleaned, 0, 2);
        $suffix = substr($cleaned, -2);

        return $prefix . ' *** *** ' . $suffix;
    }

    public static function maskAddress(?string $address): string
    {
        if (empty($address)) {
            return '';
        }

        $parts = preg_split('/[,;\n]+/', trim($address));
        if ($parts && count($parts) > 1) {
            return trim($parts[0]) . ', [адрес скрыт]';
        }

        return mb_substr(trim($address), 0, min(10, mb_strlen(trim($address)))) . '... [скрыто]';
    }

    public static function maskValue(string $key, mixed $value, bool $allowPii, bool $maskPii): mixed
    {
        if ($value === null || !is_scalar($value)) {
            return $value;
        }

        $lowerKey = strtolower($key);
        $strValue = (string) $value;

        if (!$allowPii) {
            return '[REDACTED]';
        }

        if (!$maskPii) {
            return $value;
        }

        if (str_contains($lowerKey, 'email') || filter_var($strValue, FILTER_VALIDATE_EMAIL)) {
            return self::maskEmail($strValue);
        }

        if (str_contains($lowerKey, 'phone') || str_contains($lowerKey, 'tel') || str_contains($lowerKey, 'mobile')) {
            return self::maskPhone($strValue);
        }

        if (str_contains($lowerKey, 'address') || str_contains($lowerKey, 'street')) {
            return self::maskAddress($strValue);
        }

        return $value;
    }

    public static function maskArray(array $data, bool $allowPii, bool $maskPii): array
    {
        $result = [];
        foreach ($data as $key => $val) {
            $lowerKey = is_string($key) ? strtolower($key) : '';
            $isPii = in_array($lowerKey, self::PII_KEYS, true);

            if (is_array($val)) {
                $result[$key] = self::maskArray($val, $allowPii, $maskPii);
            } elseif ($isPii) {
                $result[$key] = self::maskValue($lowerKey, $val, $allowPii, $maskPii);
            } else {
                $result[$key] = $val;
            }
        }

        return $result;
    }

    public static function sanitizeLogArguments(array $arguments): array
    {
        $clean = $arguments;
        unset($clean['password'], $clean['token'], $clean['secret'], $clean['api_key']);

        return self::maskArray($clean, false, true);
    }
}
