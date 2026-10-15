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
 * Ожидаемая ошибка инструмента. Её текст безопасно показывать ассистенту.
 * Любое другое исключение клиенту отдаётся без подробностей, подробности идут в журнал.
 */
final class ToolException extends \RuntimeException
{
    public const FORBIDDEN = 'forbidden';
    public const NOT_FOUND = 'not_found';
    public const VALIDATION = 'validation';
    public const CONFLICT = 'conflict';

    /**
     * @param   array<string, mixed>  $details  Подсказки ассистенту (допустимые значения и т. п.)
     */
    public function __construct(
        string $message,
        public readonly string $kind = self::VALIDATION,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }

    public static function forbidden(string $message): self
    {
        return new self($message, self::FORBIDDEN);
    }

    public static function notFound(string $message): self
    {
        return new self($message, self::NOT_FOUND);
    }

    public static function validation(string $message, array $details = []): self
    {
        return new self($message, self::VALIDATION, $details);
    }

    public static function conflict(string $message): self
    {
        return new self($message, self::CONFLICT);
    }
}
