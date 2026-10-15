<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Site\Tool;

\defined('_JEXEC') or die;

/**
 * Основа для инструментов: разумные значения по умолчанию и проверка аргументов.
 */
abstract class AbstractTool implements ToolInterface
{
    public function getTitle(): string
    {
        return $this->getName();
    }

    public function getRequiredScope(): string
    {
        return '';
    }

    public function isIdempotent(): bool
    {
        return $this->getAccess() === Access::Read;
    }

    public function needsConfirmation(array $arguments, ToolContext $context): bool
    {
        return false;
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        return null;
    }

    /**
     * Скрывать ли в ответе значения «персональных» ключей (address, phone, email...). По умолчанию да.
     * Инструмент отвечает «нет» только когда уверен, что в этом вызове такие ключи — не данные покупателей
     * (например, адрес и телефон склада магазина).
     */
    public function masksPersonalData(array $arguments): bool
    {
        return true;
    }

    /**
     * Схема объекта: свойства и список обязательных.
     *
     * @param   array<string, array>  $properties
     * @param   string[]              $required
     */
    protected static function schema(array $properties = [], array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    protected static function str(string $description, array $extra = []): array
    {
        return ['type' => 'string', 'description' => $description] + $extra;
    }

    protected static function int(string $description, array $extra = []): array
    {
        return ['type' => 'integer', 'description' => $description] + $extra;
    }

    protected static function bool(string $description): array
    {
        return ['type' => 'boolean', 'description' => $description];
    }

    /** Свойства постраничной выдачи для списковых инструментов. */
    protected static function paging(int $maxLimit = 100): array
    {
        return [
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => $maxLimit, 'description' => 'Page size (default 20).'],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Number of items to skip (default 0).'],
        ];
    }

    protected static function requireString(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? null;
        if (!\is_string($value) || trim($value) === '') {
            throw ToolException::validation("Parameter '$key' is required and must be a non-empty string.");
        }

        return trim($value);
    }

    protected static function requireInt(array $arguments, string $key): int
    {
        $value = $arguments[$key] ?? null;
        if (!\is_int($value) && !(\is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            throw ToolException::validation("Parameter '$key' is required and must be an integer.");
        }

        return (int) $value;
    }

    protected static function optionalInt(array $arguments, string $key): ?int
    {
        if (!\array_key_exists($key, $arguments) || $arguments[$key] === null || $arguments[$key] === '') {
            return null;
        }

        return self::requireInt($arguments, $key);
    }

    protected static function optionalString(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!\is_string($value)) {
            throw ToolException::validation("Parameter '$key' must be a string.");
        }

        return $value;
    }

    protected static function oneOf(array $arguments, string $key, array $allowed, ?string $default = null): ?string
    {
        $value = $arguments[$key] ?? $default;
        if ($value === null) {
            return null;
        }
        if (!\in_array($value, $allowed, true)) {
            throw ToolException::validation("Parameter '$key' must be one of: " . implode(', ', $allowed) . '.', ['allowed' => $allowed]);
        }

        return (string) $value;
    }
}
