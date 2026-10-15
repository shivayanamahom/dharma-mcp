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
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;

final class CategoriesSetStateTool extends AbstractContentTool
{
    public function getName(): string
    {
        return 'categories.set_state';
    }

    public function getTitle(): string
    {
        return 'Publish or hide article category';
    }

    public function getDescription(): string
    {
        return 'Publishes, unpublishes, archives or moves to trash one article category. Publishing makes the category and its published articles '
            . 'visible to all visitors, so the user must confirm. Nothing is deleted permanently.';
    }

    public function getRequiredScope(): string
    {
        return 'content:write';
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
        return self::schema([
            'id' => self::int('Category id (see categories.list).'),
            'state' => ['type' => 'string', 'enum' => array_keys(self::STATES), 'description' => 'New state.'],
        ], ['id', 'state']);
    }

    /** @return array{0: object, 1: int} */
    private function find(array $arguments, ToolContext $context): array
    {
        $id = self::requireInt($arguments, 'id');
        $db = $context->db;
        $row = $db->setQuery(
            $db->createQuery()->select(['id', 'title', 'published', 'extension'])->from($db->quoteName('#__categories'))->where('id = ' . $id)
        )->loadObject();

        if (!$row || $row->extension !== 'com_content') {
            throw ToolException::notFound("Article category $id not found.");
        }
        $context->requireCan('core.edit.state', 'com_content.category.' . $id, 'change the state of this category');

        return [$row, $id];
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        [$row, $id] = $this->find($arguments, $context);

        return [
            'action' => 'Change publication state',
            'category' => ['id' => $id, 'title' => (string) $row->title],
            'from' => self::stateName((int) $row->published),
            'to' => self::oneOf($arguments, 'state', array_keys(self::STATES)),
        ];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        [$row, $id] = $this->find($arguments, $context);
        $state = self::stateArgument($arguments);
        if ($state === null) {
            throw ToolException::validation("Parameter 'state' is required.");
        }

        $this->lang($context, 'com_categories');
        $context->app->getInput()->set('extension', 'com_content');
        $model = $context->model('com_categories', 'Category');
        $model->setState('category.extension', 'com_content');
        $pks = [$id];
        if (!$model->publish($pks, $state)) {
            throw self::modelFailure($model, 'Joomla could not change the state');
        }

        return ['id' => $id, 'state' => self::stateName($state), 'changed' => true];
    }
}
