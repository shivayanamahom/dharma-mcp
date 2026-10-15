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

final class MenusItemSetStateTool extends AbstractContentTool
{
    public function getName(): string
    {
        return 'menus.item_set_state';
    }

    public function getTitle(): string
    {
        return 'Publish or hide menu item';
    }

    public function getDescription(): string
    {
        return 'Publishes, unpublishes or moves to trash one menu item. Publishing changes the live navigation, so the user must confirm. '
            . 'The home page item cannot be unpublished or trashed.';
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
            'id' => self::int('Menu item id (see menus.items).'),
            'state' => ['type' => 'string', 'enum' => ['published', 'unpublished', 'trashed'], 'description' => 'New state.'],
        ], ['id', 'state']);
    }

    private function item(ToolContext $context, int $id): object
    {
        $this->lang($context, 'com_menus');
        $item = $context->model('com_menus', 'Item')->getItem($id);
        if (!$item || empty($item->id)) {
            throw ToolException::notFound("Menu item $id not found.");
        }
        $context->requireCan('core.edit.state', 'com_menus.menu.' . $this->menuId($context, (string) $item->menutype), 'change menu items');

        return $item;
    }

    /** Главную страницу нельзя скрыть: сайт без неё перестаёт открываться. Joomla сама этого не запрещает. */
    private function guardHome(object $item, string $target): void
    {
        if ((int) $item->home === 1 && $target !== 'published') {
            throw ToolException::forbidden(
                'This is the home page of the site and it cannot be unpublished or trashed. Make another item the home page in the Joomla admin first.'
            );
        }
    }

    /** Идентификатор меню для проверки прав на уровне меню. */
    private function menuId(ToolContext $context, string $menutype): string
    {
        return (string) $this->menuTypeId($context, $menutype);
    }

    private function menuTypeId(ToolContext $context, string $menutype): int
    {
        $db = $context->db;
        $query = $db->createQuery()
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__menu_types'))
            ->where($db->quoteName('menutype') . ' = :menutype')
            ->bind(':menutype', $menutype);

        return (int) $db->setQuery($query)->loadResult();
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        $id = self::requireInt($arguments, 'id');
        $item = $this->item($context, $id);
        $this->guardHome($item, (string) self::oneOf($arguments, 'state', ['published', 'unpublished', 'trashed']));

        return [
            'action' => 'Change menu item state',
            'item' => ['id' => $id, 'title' => (string) $item->title, 'menu' => (string) $item->menutype],
            'from' => self::stateName((int) $item->published),
            'to' => self::oneOf($arguments, 'state', ['published', 'unpublished', 'trashed']),
        ];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $id = self::requireInt($arguments, 'id');
        $name = self::oneOf($arguments, 'state', ['published', 'unpublished', 'trashed']);
        if ($name === null) {
            throw ToolException::validation("Parameter 'state' is required.");
        }
        $this->guardHome($this->item($context, $id), $name);

        $model = $context->model('com_menus', 'Item');
        $pks = [$id];
        if (!$model->publish($pks, self::STATES[$name])) {
            throw self::modelFailure($model, 'Joomla could not change the state');
        }

        return ['id' => $id, 'state' => $name, 'changed' => true];
    }
}
