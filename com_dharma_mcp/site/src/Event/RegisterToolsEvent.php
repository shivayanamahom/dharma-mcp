<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Site\Event;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Tool\ToolInterface;
use Dharma\Component\DharmaMcp\Site\Tool\ToolRegistry;
use Joomla\Event\Event;

/**
 * Событие onDharmaMcpRegisterTools: плагины группы dharmamcp добавляют сюда свои инструменты.
 */
final class RegisterToolsEvent extends Event
{
    public function __construct(string $name, private readonly ToolRegistry $registry)
    {
        parent::__construct($name, []);
    }

    public function register(ToolInterface ...$tools): void
    {
        foreach ($tools as $tool) {
            $this->registry->register($tool);
        }
    }
}
