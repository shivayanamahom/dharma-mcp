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
use Joomla\CMS\Table\Module as ModuleTable;
use Joomla\Registry\Registry;

/**
 * Правка модуля сайта через таблицу Joomla. Привязка к страницам меню (#__modules_menu) не затрагивается:
 * поэтому не используется ModuleModel::save(), который пересоздаёт привязку.
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
            . 'and individual parameters (merged into the existing ones). Pages the module is shown on are not touched. '
            . 'The change is live immediately, so the user must confirm. Read the module with modules.get first.';
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
        if ($changes === []) {
            throw ToolException::validation('Pass at least one field to change.');
        }

        return $changes;
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

        $diff = [];
        foreach ($changes as $key => $new) {
            $old = $key === 'params' ? '(parameters merged)' : ($row->$key ?? null);
            $diff[$key] = ['from' => self::shorten($old), 'to' => self::shorten($new)];
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
        $this->lang($context, 'com_modules');

        $table = new ModuleTable($context->db);
        if (!$table->load($id)) {
            throw ToolException::notFound("Module $id not found.");
        }

        if (isset($changes['params'])) {
            $registry = new Registry((string) $table->params);
            foreach ($changes['params'] as $key => $value) {
                $registry->set((string) $key, $value);
            }
            $changes['params'] = $registry->toString();
        }

        if (!$table->bind($changes) || !$table->check() || !$table->store()) {
            throw ToolException::validation('Joomla could not save the module: ' . strip_tags((string) $table->getError()));
        }

        // Кэш модулей, иначе посетители увидят старую версию до истечения срока.
        Factory::getContainer()->get(CacheControllerFactoryInterface::class)
            ->createCacheController('callback', ['defaultgroup' => 'com_modules'])
            ->clean();

        return ['updated' => true, 'id' => $id, 'changed_fields' => array_keys($changes)];
    }

    private static function shorten(mixed $value): mixed
    {
        return \is_string($value) && mb_strlen($value) > 300 ? mb_substr($value, 0, 300) . '…' : $value;
    }
}
