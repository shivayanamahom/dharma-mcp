<?php

/**
 * Dharma MCP — набор «Система».
 *
 * @package    plg_dharmamcp_system
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\System\Tool;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;

final class SystemConfigGetTool extends AbstractSystemTool
{
    public function getName(): string
    {
        return 'system.config_get';
    }

    public function getTitle(): string
    {
        return 'Read global configuration';
    }

    public function getDescription(): string
    {
        return 'Reads Joomla global configuration values (site name, offline mode, SEO meta, cache, SEF, sessions, mail sender...). '
            . 'Secrets such as database, mail and secret keys are never returned. Also lists which keys can be changed with system.config_set.';
    }

    public function getRequiredScope(): string
    {
        return 'system:read';
    }

    public function getAccess(): Access
    {
        return Access::Read;
    }

    public function getInputSchema(): array
    {
        return self::schema();
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $this->admin($context);

        $values = [];
        foreach (self::READABLE_CONFIG as $key) {
            $values[$key] = $context->app->get($key);
        }

        return ['config' => $values, 'writable_keys' => array_map(
            static fn ($rule): mixed => \is_array($rule) ? ['one_of' => $rule] : $rule,
            self::WRITABLE_CONFIG
        )];
    }
}
