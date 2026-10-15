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

final class MenusItemsTool extends AbstractContentTool
{
    public function getName(): string
    {
        return 'menus.items';
    }

    public function getTitle(): string
    {
        return 'List menu items';
    }

    public function getDescription(): string
    {
        return 'Lists menu items of one menu as a tree: id, title, alias, link, type, state, parent, depth, home flag. '
            . 'Get the menutype from menus.list.';
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
        return self::schema([
            'menutype' => self::str('Menu type, e.g. "mainmenu" (see menus.list).'),
            'state' => ['type' => 'string', 'enum' => ['published', 'unpublished', 'trashed', 'all'], 'description' => 'Default: published and unpublished.'],
        ] + self::paging(200), ['menutype']);
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $context->requireCan('core.manage', 'com_menus', 'view menu items');
        $this->lang($context, 'com_menus');
        [$limit, $offset] = $context->page($arguments, 100, 200);

        $stateArg = self::oneOf($arguments, 'state', ['published', 'unpublished', 'trashed', 'all'], null);
        $model = $context->model('com_menus', 'Items');
        $model->setState('filter.client_id', 0);
        $model->setState('filter.menutype', self::requireString($arguments, 'menutype'));
        $model->setState('filter.published', $stateArg === null ? '' : ($stateArg === 'all' ? '*' : self::STATES[$stateArg]));
        $model->setState('list.ordering', 'a.lft');
        $model->setState('list.direction', 'ASC');
        $model->setState('list.start', $offset);
        $model->setState('list.limit', $limit);

        $items = [];
        foreach ($model->getItems() ?: [] as $i) {
            $items[] = [
                'id' => (int) $i->id,
                'title' => (string) $i->title,
                'alias' => (string) $i->alias,
                'type' => (string) $i->type,
                'link' => (string) $i->link,
                'state' => self::stateName((int) $i->published),
                'parent_id' => (int) $i->parent_id,
                'depth' => (int) $i->level,
                'home' => (bool) $i->home,
                'access' => (int) $i->access,
            ];
        }
        $total = (int) $model->getTotal();

        return ['total' => $total, 'limit' => $limit, 'offset' => $offset, 'has_more' => $offset + \count($items) < $total, 'items' => $items];
    }
}
