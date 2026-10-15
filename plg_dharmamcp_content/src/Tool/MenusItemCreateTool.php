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
use Joomla\CMS\Component\ComponentHelper;

final class MenusItemCreateTool extends AbstractContentTool
{
    public function getName(): string
    {
        return 'menus.item_create';
    }

    public function getTitle(): string
    {
        return 'Create menu item draft';
    }

    public function getDescription(): string
    {
        return 'Creates a menu item as an UNPUBLISHED draft. Kinds: "article" (a page showing one article; give article_id) '
            . 'or "url" (an external or internal link; give url). Publish it later with menus.item_set_state after the owner agrees.';
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
            'menutype' => self::str('Menu type (see menus.list).'),
            'title' => self::str('Menu item title.'),
            'kind' => ['type' => 'string', 'enum' => ['article', 'url'], 'description' => 'What the item opens.'],
            'article_id' => self::int('Article id for kind "article".'),
            'url' => self::str('Address for kind "url".'),
            'parent_id' => self::int('Parent menu item id; 1 or omitted for the top level.'),
            'alias' => self::str('URL alias (optional).'),
        ], ['menutype', 'title', 'kind']);
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $context->requireCan('core.create', 'com_menus', 'create menu items');
        $this->lang($context, 'com_menus', 'com_content');

        $kind = self::oneOf($arguments, 'kind', ['article', 'url']);
        $data = [
            'id' => 0,
            'menutype' => self::requireString($arguments, 'menutype'),
            'title' => self::requireString($arguments, 'title'),
            'alias' => self::optionalString($arguments, 'alias') ?? '',
            'parent_id' => self::optionalInt($arguments, 'parent_id') ?? 1,
            'published' => 0,
            'access' => 1,
            'language' => '*',
            'client_id' => 0,
            'home' => 0,
            'browserNav' => 0,
            'template_style_id' => 0,
            'params' => [],
        ];

        if ($kind === 'article') {
            $articleId = self::requireInt($arguments, 'article_id');
            ArticlesGetTool::load($context, $articleId);
            $data += [
                'type' => 'component',
                'link' => 'index.php?option=com_content&view=article&id=' . $articleId,
                'component_id' => (int) ComponentHelper::getComponent('com_content')->id,
                'request' => ['id' => $articleId],
            ];
        } else {
            $url = self::requireString($arguments, 'url');
            if (!preg_match('#^(https?://|/|index\.php)#i', $url)) {
                throw ToolException::validation('The url must start with http://, https://, / or index.php.');
            }
            $data += ['type' => 'url', 'link' => $url, 'component_id' => 0];
        }

        $model = $context->model('com_menus', 'Item');
        if (!$model->save($data)) {
            throw self::modelFailure($model, 'Joomla could not save the menu item');
        }

        return [
            'created' => true,
            'id' => (int) $model->getState('item.id'),
            'state' => 'unpublished',
            'note' => 'Saved unpublished. Publish it with menus.item_set_state after review.',
        ];
    }
}
