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

final class ModulesSetStateTool extends AbstractContentTool
{
    public function getName(): string
    {
        return 'modules.set_state';
    }

    public function getTitle(): string
    {
        return 'Publish or hide module';
    }

    public function getDescription(): string
    {
        return 'Publishes, unpublishes or moves to trash one site module. This changes what visitors see right away, so the user must confirm.';
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
            'id' => self::int('Module id (see modules.list).'),
            'state' => ['type' => 'string', 'enum' => ['published', 'unpublished', 'trashed'], 'description' => 'New state.'],
        ], ['id', 'state']);
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        $id = self::requireInt($arguments, 'id');
        $row = ModulesGetTool::row($context, $id);
        $context->requireCan('core.edit.state', 'com_modules.module.' . $id, 'change the state of this module');

        return [
            'action' => 'Change module state',
            'module' => ['id' => $id, 'title' => (string) $row->title, 'type' => (string) $row->module, 'position' => (string) $row->position],
            'from' => self::stateName((int) $row->published),
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
        ModulesGetTool::row($context, $id);
        $context->requireCan('core.edit.state', 'com_modules.module.' . $id, 'change the state of this module');
        $this->lang($context, 'com_modules');

        $model = $context->model('com_modules', 'Module');
        $pks = [$id];
        if (!$model->publish($pks, self::STATES[$name])) {
            throw self::modelFailure($model, 'Joomla could not change the state');
        }

        return ['id' => $id, 'state' => $name, 'changed' => true];
    }
}
