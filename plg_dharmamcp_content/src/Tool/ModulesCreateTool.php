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

final class ModulesCreateTool extends AbstractContentTool
{
    public function getName(): string
    {
        return 'modules.create_custom';
    }

    public function getTitle(): string
    {
        return 'Create custom HTML module draft';
    }

    public function getDescription(): string
    {
        return 'Creates a custom HTML module (mod_custom) as an UNPUBLISHED draft in a template position, shown on all pages once published. '
            . 'Typical uses: a promo banner, a notice, a block with phone numbers. Check positions with modules.positions. '
            . 'Publish it later with modules.set_state after the owner agrees.';
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
            'title' => self::str('Module title (also shown to visitors unless show_title is false).'),
            'position' => self::str('Template position, e.g. "footer" (see modules.positions).'),
            'content' => self::str('HTML content of the module.'),
            'show_title' => self::bool('Show the title above the content (default true).'),
            'note' => self::str('Internal note for administrators (optional).'),
        ], ['title', 'position', 'content']);
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $context->requireCan('core.create', 'com_modules', 'create modules');
        $this->lang($context, 'com_modules');

        $model = $context->model('com_modules', 'Module');
        $data = [
            'id' => 0,
            'title' => self::requireString($arguments, 'title'),
            'note' => self::optionalString($arguments, 'note') ?? 'Created through Dharma MCP',
            'module' => 'mod_custom',
            'client_id' => 0,
            'position' => self::requireString($arguments, 'position'),
            'published' => 0,
            'access' => 1,
            'showtitle' => \array_key_exists('show_title', $arguments) && !$arguments['show_title'] ? 0 : 1,
            'language' => '*',
            'content' => self::filterHtml(self::requireString($arguments, 'content')),
            'params' => ['prepare_content' => 0, 'backgroundimage' => '', 'layout' => '_:default', 'moduleclass_sfx' => '', 'cache' => 1, 'cache_time' => 900, 'cachemode' => 'static', 'style' => '0'],
            'assignment' => 0,
            'assigned' => [],
        ];

        if (!$model->save($data)) {
            throw self::modelFailure($model, 'Joomla could not save the module');
        }

        return [
            'created' => true,
            'id' => (int) $model->getState('module.id'),
            'state' => 'unpublished',
            'note' => 'Saved unpublished, for all pages once published. Publish it with modules.set_state after review.',
        ];
    }
}
