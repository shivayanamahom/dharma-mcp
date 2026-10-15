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

final class ModulesListTool extends AbstractContentTool
{
    public function getName(): string
    {
        return 'modules.list';
    }

    public function getTitle(): string
    {
        return 'List site modules';
    }

    public function getDescription(): string
    {
        return 'Lists the modules of the public site (blocks such as menus, custom HTML, banners, cart) with their template position and state. '
            . 'Filter by position, module type (e.g. mod_custom) or state. Use modules.get for the content and parameters.';
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
            'position' => self::str('Template position, e.g. "footer"; "none" for modules without a position.'),
            'module' => self::str('Module type, e.g. "mod_custom".'),
            'search' => self::str('Words from the module title.'),
            'state' => ['type' => 'string', 'enum' => ['published', 'unpublished', 'trashed', 'all'], 'description' => 'Default: published and unpublished.'],
        ] + self::paging(200));
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $context->requireCan('core.manage', 'com_modules', 'view modules');
        $this->lang($context, 'com_modules');
        [$limit, $offset] = $context->page($arguments, 50, 200);

        $stateArg = self::oneOf($arguments, 'state', ['published', 'unpublished', 'trashed', 'all'], null);
        $model = $context->model('com_modules', 'Modules');
        $model->setState('client_id', 0);
        $model->setState('filter.client_id', 0);
        $model->setState('filter.state', $stateArg === null ? '' : ($stateArg === 'all' ? '*' : self::STATES[$stateArg]));
        $model->setState('filter.position', self::optionalString($arguments, 'position') ?? '');
        $model->setState('filter.module', self::optionalString($arguments, 'module') ?? '');
        $model->setState('filter.search', self::optionalString($arguments, 'search') ?? '');
        $model->setState('list.ordering', 'a.position');
        $model->setState('list.direction', 'ASC');
        $model->setState('list.start', $offset);
        $model->setState('list.limit', $limit);

        $items = [];
        foreach ($model->getItems() ?: [] as $m) {
            $items[] = [
                'id' => (int) $m->id,
                'title' => (string) $m->title,
                'module' => (string) $m->module,
                'position' => (string) $m->position,
                'state' => self::stateName((int) $m->published),
                'access' => (int) $m->access,
                'ordering' => (int) $m->ordering,
                'note' => (string) ($m->note ?? ''),
            ];
        }
        $total = (int) $model->getTotal();

        return ['total' => $total, 'limit' => $limit, 'offset' => $offset, 'has_more' => $offset + \count($items) < $total, 'items' => $items];
    }
}
