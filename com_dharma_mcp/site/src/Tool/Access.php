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
 * Уровень воздействия инструмента. От него зависят режим сервера и подтверждение.
 *
 * - Read   — ничего не меняет;
 * - Draft  — создаёт или меняет только то, что не видно посетителям (черновик, снятое с публикации);
 * - Write  — меняет «живые» данные сайта (опубликованный текст, цену, статус заказа);
 * - Danger — удаление, перезапись файлов, настройки: подтверждение человеком обязательно всегда.
 */
enum Access: string
{
    case Read = 'read';
    case Draft = 'draft';
    case Write = 'write';
    case Danger = 'danger';

    public function isReadOnly(): bool
    {
        return $this === self::Read;
    }

    public function isDestructive(): bool
    {
        return $this === self::Danger;
    }
}
