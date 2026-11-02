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
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Table\Menu as MenuTable;
use Joomla\Registry\Registry;

/**
 * Правка модуля сайта и привязки к страницам через штатную модель Joomla.
 */
final class ModulesUpdateTool extends AbstractContentTool
{
    public function getName(): string
    {
        return 'modules.update';
    }

    public function getTitle(): string
    {
        return 'Update module';
    }

    public function getDescription(): string
    {
        return 'Changes a site module: title, template position, whether the title is shown, the HTML content of a custom module (mod_custom), '
            . 'individual parameters (merged into the current ones), and optionally its menu-page assignment. '
            . 'When menu_assignment is omitted, the current assignment is preserved. The change is live immediately, so the user must confirm. '
            . 'Read the module with modules.get and menu items with menus.items first.';
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
            'id' => self::int('Module id.'),
            'title' => self::str('New title.'),
            'position' => self::str('Template position (see modules.positions).'),
            'show_title' => self::bool('Whether the module title is shown to visitors.'),
            'content' => self::str('New HTML for a custom module (mod_custom only).'),
            'params' => ['type' => 'object', 'description' => 'Parameters to set; they are merged into the current ones.'],
            'menu_assignment' => [
                'type' => 'object',
                'description' => 'Optional page assignment: mode is all, none, only, or except. For only/except, provide menu_item_ids from menus.items. Omit this object to preserve the existing assignment.',
                'properties' => [
                    'mode' => ['type' => 'string', 'enum' => ['all', 'none', 'only', 'except']],
                    'menu_item_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer', 'minimum' => 2],
                        'maxItems' => 100,
                        'uniqueItems' => true,
                    ],
                ],
                'required' => ['mode'],
                'additionalProperties' => false,
            ],
        ], ['id']);
    }

    /** @return array<string, mixed> */
    private function changes(array $arguments, object $row): array
    {
        $changes = [];
        foreach (['title', 'position'] as $field) {
            if (\array_key_exists($field, $arguments)) {
                if (!\is_string($arguments[$field]) || ($field === 'title' && trim($arguments[$field]) === '')) {
                    throw ToolException::validation("Parameter '$field' must be a non-empty string.");
                }
                $changes[$field] = $arguments[$field];
            }
        }
        if (\array_key_exists('show_title', $arguments)) {
            if (!\is_bool($arguments['show_title'])) {
                throw ToolException::validation("Parameter 'show_title' must be a boolean.");
            }
            $changes['showtitle'] = $arguments['show_title'] ? 1 : 0;
        }
        if (\array_key_exists('content', $arguments)) {
            if ($row->module !== 'mod_custom') {
                throw ToolException::validation('Only custom HTML modules (mod_custom) have editable content.');
            }
            if (!\is_string($arguments['content'])) {
                throw ToolException::validation("Parameter 'content' must be a string.");
            }
            $changes['content'] = $arguments['content'];
        }
        if (\array_key_exists('params', $arguments)) {
            if (!\is_array($arguments['params']) || $arguments['params'] === []) {
                throw ToolException::validation("Parameter 'params' must be a non-empty object.");
            }
            $changes['params'] = $arguments['params'];
        }
        return $changes;
    }

    /**
     * Validate a requested menu assignment and return Joomla ModuleModel values.
     *
     * @return array{assignment:int|string,assigned:int[],mode:string,pages:int[]}|null
     */
    private function menuAssignment(array $arguments, ToolContext $context): ?array
    {
        if (!\array_key_exists('menu_assignment', $arguments)) {
            return null;
        }

        $value = $arguments['menu_assignment'];
        if (!\is_array($value) || !isset($value['mode']) || !\is_string($value['mode'])) {
            throw ToolException::validation("Parameter 'menu_assignment' must contain a mode.");
        }

        $mode = $value['mode'];
        if (!\in_array($mode, ['all', 'none', 'only', 'except'], true)) {
            throw ToolException::validation("Parameter 'menu_assignment.mode' must be all, none, only, or except.");
        }

        $ids = $value['menu_item_ids'] ?? [];
        if (!\is_array($ids) || !array_is_list($ids) || \count($ids) > 100) {
            throw ToolException::validation("Parameter 'menu_assignment.menu_item_ids' must be an array of up to 100 menu item ids.");
        }
        foreach ($ids as $menuId) {
            if (!\is_int($menuId) || $menuId < 2) {
                throw ToolException::validation('Menu item ids must be integers greater than 1.');
            }
        }
        if (\count(array_unique($ids)) !== \count($ids)) {
            throw ToolException::validation('Duplicate menu item ids are not allowed.');
        }

        if (\in_array($mode, ['only', 'except'], true) && $ids === []) {
            throw ToolException::validation("Parameter 'menu_assignment.menu_item_ids' must not be empty for mode '$mode'.");
        }
        if (\in_array($mode, ['all', 'none'], true) && $ids !== []) {
            throw ToolException::validation("Parameter 'menu_assignment.menu_item_ids' must be empty for mode '$mode'.");
        }

        if ($ids !== []) {
            $this->lang($context, 'com_menus');
            foreach ($ids as $menuId) {
                $menu = new MenuTable($context->db);
                if (!$menu->load($menuId) || (int) $menu->client_id !== 0 || (string) $menu->menutype === '') {
                    throw ToolException::notFound("Site menu item $menuId not found.");
                }
                if ((int) $menu->published === -2) {
                    throw ToolException::validation("Menu item $menuId is in the trash.");
                }

                $query = $context->db->createQuery()
                    ->select($context->db->quoteName('id'))
                    ->from($context->db->quoteName('#__menu_types'))
                    ->where($context->db->quoteName('menutype') . ' = :menutype')
                    ->bind(':menutype', $menu->menutype);
                $menuTypeId = (int) $context->db->setQuery($query)->loadResult();
                if ($menuTypeId < 1) {
                    throw ToolException::notFound("Menu type for menu item $menuId not found.");
                }
                $context->requireCan('core.edit', 'com_menus.menu.' . $menuTypeId, 'edit this menu item');
            }
        }

        return [
            'assignment' => match ($mode) {
                'all' => 0,
                'none' => '-',
                'only' => 1,
                'except' => -1,
            },
            'assigned' => $ids,
            'mode' => $mode,
            'pages' => $ids,
        ];
    }

    /** @return array{assignment:int|string,assigned:int[],mode:string,menu_item_ids:int[]} */
    private function currentMenuAssignment(ToolContext $context, int $id): array
    {
        $current = ModulesGetTool::assignment($context, $id);
        $mode = match ($current['assignment']) {
            'all pages' => 'all',
            'none' => 'none',
            'only the listed pages' => 'only',
            'all pages except the listed' => 'except',
            default => 'none',
        };

        return [
            'assignment' => match ($mode) {
                'all' => 0,
                'only' => 1,
                'except' => -1,
                default => '-',
            },
            'assigned' => $current['pages'],
            'mode' => $mode,
            'menu_item_ids' => $current['pages'],
        ];
    }

    private function authorize(ToolContext $context, int $id): void
    {
        $context->requireCan('core.edit', 'com_modules.module.' . $id, 'edit this module');
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        $id = self::requireInt($arguments, 'id');
        $row = ModulesGetTool::row($context, $id);
        $this->authorize($context, $id);
        $changes = $this->changes($arguments, $row);
        $requestedAssignment = $this->menuAssignment($arguments, $context);
        if ($changes === [] && $requestedAssignment === null) {
            throw ToolException::validation('Pass at least one field to change.');
        }

        $diff = [];
        foreach ($changes as $key => $new) {
            $old = $key === 'params' ? '(parameters merged)' : ($row->$key ?? null);
            $diff[$key] = ['from' => self::shorten($old), 'to' => self::shorten($new)];
        }
        if ($requestedAssignment !== null) {
            $currentAssignment = $this->currentMenuAssignment($context, $id);
            $diff['menu_assignment'] = [
                'from' => ['mode' => $currentAssignment['mode'], 'menu_item_ids' => $currentAssignment['menu_item_ids']],
                'to' => ['mode' => $requestedAssignment['mode'], 'menu_item_ids' => $requestedAssignment['pages']],
            ];
        }

        return [
            'action' => 'Update module',
            'module' => ['id' => $id, 'title' => (string) $row->title, 'type' => (string) $row->module, 'state' => self::stateName((int) $row->published)],
            'live_now' => (int) $row->published === 1,
            'changes' => $diff,
        ];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $id = self::requireInt($arguments, 'id');
        $row = ModulesGetTool::row($context, $id);
        $this->authorize($context, $id);
        $changes = $this->changes($arguments, $row);
        $requestedAssignment = $this->menuAssignment($arguments, $context);
        if ($changes === [] && $requestedAssignment === null) {
            throw ToolException::validation('Pass at least one field to change.');
        }
        $this->lang($context, 'com_modules');

        $table = $context->table('com_modules', 'Module');
        if (!$table->load($id)) {
            throw ToolException::notFound("Module $id not found.");
        }

        if (isset($changes['params'])) {
            $registry = new Registry((string) $table->params);
            foreach ($changes['params'] as $key => $value) {
                $registry->set((string) $key, $value);
            }
            $changes['params'] = $registry->toArray();
        }

        $assignment = $requestedAssignment ?? $this->currentMenuAssignment($context, $id);
        $saveData = [
            'id' => $id,
            'title' => (string) $table->title,
            'note' => (string) $table->note,
            'module' => (string) $table->module,
            'client_id' => (int) $table->client_id,
            'position' => (string) $table->position,
            'published' => (int) $table->published,
            'access' => (int) $table->access,
            'showtitle' => (int) $table->showtitle,
            'language' => (string) $table->language,
            'content' => (string) $table->content,
            'params' => (new Registry((string) $table->params))->toArray(),
            'assignment' => $assignment['assignment'],
            'assigned' => $assignment['assigned'],
        ];
        $saveData = array_merge($saveData, $changes);

        $model = $context->model('com_modules', 'Module');
        if (!$model->save($saveData)) {
            throw self::modelFailure($model, 'Joomla could not save the module');
        }

        // Модель пересоздаёт запись modules_menu; очистим связанные кэши.
        $cache = Factory::getContainer()->get(CacheControllerFactoryInterface::class);
        foreach (['_system', 'com_modules'] as $group) {
            $cache->createCacheController('callback', ['defaultgroup' => $group])->clean();
        }

        if ($requestedAssignment !== null) {
            $cache->createCacheController('callback', ['defaultgroup' => 'com_menus'])->clean();
        }

        return [
            'updated' => true,
            'id' => $id,
            'changed_fields' => array_merge(array_keys($changes), $requestedAssignment !== null ? ['menu_assignment'] : []),
            'shown_on' => ModulesGetTool::assignment($context, $id),
        ];
    }

    private static function shorten(mixed $value): mixed
    {
        return \is_string($value) && mb_strlen($value) > 300 ? mb_substr($value, 0, 300) . '…' : $value;
    }
}
