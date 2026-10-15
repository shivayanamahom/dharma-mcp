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

use Dharma\Component\DharmaMcp\Site\Event\RegisterToolsEvent;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Plugin\PluginHelper;

/**
 * Реестр инструментов. Ядро знает только ping; остальные приходят от плагинов группы dharmamcp,
 * поэтому отключённый плагин-набор полностью убирает свои инструменты.
 */
final class ToolRegistry
{
    public const EVENT = 'onDharmaMcpRegisterTools';

    /** @var array<string, ToolInterface> */
    private array $tools = [];

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    /**
     * Собирает реестр: встроенные инструменты и инструменты включённых плагинов-наборов.
     */
    public static function build(CMSApplicationInterface $app): self
    {
        $registry = new self();
        $registry->register(new PingTool());

        PluginHelper::importPlugin('dharmamcp');
        $app->getDispatcher()->dispatch(self::EVENT, new RegisterToolsEvent(self::EVENT, $registry));

        return $registry;
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return array<string, ToolInterface>
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * Наборы, представленные в реестре: имя => число инструментов.
     *
     * @return array<string, int>
     */
    public function packs(): array
    {
        $packs = [];
        foreach ($this->tools as $tool) {
            $packs[$tool->getPack()] = ($packs[$tool->getPack()] ?? 0) + 1;
        }

        return $packs;
    }
}
