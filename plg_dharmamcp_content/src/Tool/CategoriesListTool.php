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

final class CategoriesListTool extends AbstractContentTool
{
    public function getName(): string
    {
        return 'categories.list';
    }

    public function getTitle(): string
    {
        return 'List article categories';
    }

    public function getDescription(): string
    {
        return 'Lists the categories of Joomla articles as a tree (id, title, parent, depth, state). '
            . 'Needed to choose a category_id for articles.create and to place menu items.';
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
            'search' => self::str('Words from the category title.'),
            'state' => ['type' => 'string', 'enum' => ['published', 'unpublished', 'archived', 'trashed', 'all'], 'description' => 'Default: published and unpublished.'],
        ] + self::paging(200));
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $context->requireCan('core.manage', 'com_categories', 'view categories');
        $this->lang($context, 'com_categories');
        [$limit, $offset] = $context->page($arguments, 50, 200);

        $stateArg = self::oneOf($arguments, 'state', [...array_keys(self::STATES), 'all'], null);
        $model = $context->model('com_categories', 'Categories');
        $model->setState('filter.extension', 'com_content');
        $model->setState('filter.published', $stateArg === null ? '' : ($stateArg === 'all' ? '*' : self::STATES[$stateArg]));
        $model->setState('filter.search', self::optionalString($arguments, 'search') ?? '');
        $model->setState('list.ordering', 'a.lft');
        $model->setState('list.direction', 'ASC');
        $model->setState('list.start', $offset);
        $model->setState('list.limit', $limit);

        $items = [];
        foreach ($model->getItems() ?: [] as $c) {
            $items[] = [
                'id' => (int) $c->id,
                'title' => (string) $c->title,
                'alias' => (string) $c->alias,
                'parent_id' => (int) $c->parent_id,
                'depth' => (int) $c->level,
                'state' => self::stateName((int) $c->published),
            ];
        }
        $total = (int) $model->getTotal();

        return ['total' => $total, 'limit' => $limit, 'offset' => $offset, 'has_more' => $offset + \count($items) < $total, 'items' => $items];
    }
}
