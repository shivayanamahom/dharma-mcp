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

final class CategoriesCreateTool extends AbstractContentTool
{
    public function getName(): string
    {
        return 'categories.create';
    }

    public function getTitle(): string
    {
        return 'Create article category';
    }

    public function getDescription(): string
    {
        return 'Creates an article category as an UNPUBLISHED draft (nobody sees it until published in the Joomla admin or by the owner). '
            . 'parent_id 1 (or omitted) puts it at the top level; use categories.list to find a parent.';
    }

    public function getRequiredScope(): string
    {
        return 'content:write';
    }

    public function getAccess(): Access
    {
        return Access::Draft;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'title' => self::str('Category title.'),
            'parent_id' => self::int('Parent category id; 1 or omitted for the top level.'),
            'alias' => self::str('URL alias (optional).'),
            'description' => self::str('Category description as HTML (optional).'),
        ], ['title']);
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $title = self::requireString($arguments, 'title');
        $parent = self::optionalInt($arguments, 'parent_id') ?? 1;
        $context->requireCan('core.create', 'com_content', 'create categories');
        $this->lang($context, 'com_categories');

        // CategoryModel определяет расширение по запросу.
        $context->app->getInput()->set('extension', 'com_content');
        $model = $context->model('com_categories', 'Category');
        $model->setState('category.extension', 'com_content');

        $data = [
            'id' => 0,
            'title' => $title,
            'alias' => self::optionalString($arguments, 'alias') ?? '',
            'parent_id' => $parent,
            'extension' => 'com_content',
            'published' => 0,
            'access' => 1,
            'language' => '*',
            'description' => self::filterHtml(self::optionalString($arguments, 'description') ?? ''),
            'params' => [],
            'metadata' => [],
        ];

        if (!$model->save($data)) {
            throw self::modelFailure($model, 'Joomla could not save the category');
        }

        return [
            'created' => true,
            'id' => (int) $model->getState('category.id'),
            'state' => 'unpublished',
            'note' => 'Saved unpublished. Publish it in Components → Articles → Categories after review.',
        ];
    }
}
