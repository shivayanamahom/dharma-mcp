<?php

/**
 * Dharma MCP — набор «Материалы и меню».
 *
 * @package    plg_dharmamcp_content
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Content\Tool;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;

final class MenusListTool extends AbstractContentTool
{
    public function getName(): string
    {
        return 'menus.list';
    }

    public function getTitle(): string
    {
        return 'List menus';
    }

    public function getDescription(): string
    {
        return 'Lists the site menus (menu types such as "mainmenu") with their titles. The menutype value is used by menus.items and menus.item_create.';
    }

    public function getRequiredScope(): string
    {
        return 'content:read';
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
        $context->requireCan('core.manage', 'com_menus', 'view menus');
        $this->lang($context, 'com_menus');

        $model = $context->model('com_menus', 'Menus');
        $model->setState('client_id', 0);
        $model->setState('filter.client_id', 0);
        $model->setState('list.start', 0);
        $model->setState('list.limit', 100);

        $menus = [];
        foreach ($model->getItems() ?: [] as $menu) {
            $menus[] = [
                'menutype' => (string) $menu->menutype,
                'title' => (string) $menu->title,
                'description' => (string) ($menu->description ?? ''),
                'items_published' => isset($menu->count_published) ? (int) $menu->count_published : null,
                'items_unpublished' => isset($menu->count_unpublished) ? (int) $menu->count_unpublished : null,
            ];
        }

        return ['menus' => $menus];
    }
}
