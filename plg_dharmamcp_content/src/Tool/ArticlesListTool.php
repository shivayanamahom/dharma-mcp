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

final class ArticlesListTool extends AbstractContentTool
{
    public function getName(): string
    {
        return 'articles.list';
    }

    public function getTitle(): string
    {
        return 'List articles';
    }

    public function getDescription(): string
    {
        return 'Lists Joomla articles (the simple pages of the site: About, Delivery, Contacts, news), newest first, one page at a time. '
            . 'Filter by publication state, category id or words in the title. Use articles.get for the text.';
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
            'search' => self::str('Words from the title or alias.'),
            'category_id' => self::int('Only articles of this category (see categories.list).'),
            'state' => ['type' => 'string', 'enum' => ['published', 'unpublished', 'archived', 'trashed', 'all'], 'description' => 'Default: published and unpublished.'],
        ] + self::paging());
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $context->requireCan('core.manage', 'com_content', 'view articles');
        $this->lang($context, 'com_content');
        [$limit, $offset] = $context->page($arguments);

        $stateArg = self::oneOf($arguments, 'state', [...array_keys(self::STATES), 'all'], null);
        $model = $context->model('com_content', 'Articles');
        $model->setState('filter.published', $stateArg === null ? '' : ($stateArg === 'all' ? '*' : self::STATES[$stateArg]));
        $model->setState('filter.search', self::optionalString($arguments, 'search') ?? '');
        if (($category = self::optionalInt($arguments, 'category_id')) !== null) {
            $model->setState('filter.category_id', $category);
        }
        $model->setState('list.ordering', 'a.id');
        $model->setState('list.direction', 'DESC');
        $model->setState('list.start', $offset);
        $model->setState('list.limit', $limit);

        $items = [];
        foreach ($model->getItems() ?: [] as $a) {
            $items[] = [
                'id' => (int) $a->id,
                'title' => (string) $a->title,
                'alias' => (string) $a->alias,
                'state' => self::stateName((int) $a->state),
                'category' => ['id' => (int) $a->catid, 'title' => (string) ($a->category_title ?? '')],
                'featured' => (bool) $a->featured,
                'created' => (string) $a->created,
                'modified' => $a->modified ?: null,
                'author' => (string) ($a->author_name ?? ''),
                'hits' => (int) $a->hits,
            ];
        }

        $total = (int) $model->getTotal();

        return [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $offset + \count($items) < $total,
            'items' => $items,
        ];
    }
}
