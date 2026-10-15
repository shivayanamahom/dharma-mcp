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
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;

final class CacheClearTool extends AbstractSystemTool
{
    public function getName(): string
    {
        return 'cache.clear';
    }

    public function getTitle(): string
    {
        return 'Clear site cache';
    }

    public function getDescription(): string
    {
        return 'Clears the Joomla site cache (all cache groups). Safe, but the first visits afterwards are slower while the cache is rebuilt. '
            . 'Use it after changes that do not appear on the public site.';
    }

    public function getRequiredScope(): string
    {
        return 'system:write';
    }

    public function getAccess(): Access
    {
        return Access::Write;
    }

    public function isIdempotent(): bool
    {
        return true;
    }

    public function getInputSchema(): array
    {
        return self::schema();
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $this->admin($context);
        $context->app->getLanguage()->load('com_cache', JPATH_ADMINISTRATOR);
        $model = $context->model('com_cache', 'Cache');

        $groups = [];
        foreach ($model->getData() ?: [] as $group) {
            $groups[] = (string) $group->group;
        }

        // cleanlist() возвращает список групп, которые не удалось очистить (пустой — всё очищено).
        $failed = $groups === [] ? [] : (array) $model->cleanlist($groups);
        if ($failed !== []) {
            throw ToolException::validation('Joomla could not clear these cache groups (check file permissions of the cache folder): ' . implode(', ', $failed) . '.');
        }

        return ['cleared' => true, 'groups' => \count($groups)];
    }
}
