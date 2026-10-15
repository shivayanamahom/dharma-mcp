<?php

/**
 * Dharma MCP — набор «Система».
 *
 * @package    plg_dharmamcp_system
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\System\Tool;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Joomla\CMS\Language\Text;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

final class PluginsListTool extends AbstractSystemTool
{
    public function getName(): string
    {
        return 'plugins.list';
    }

    public function getTitle(): string
    {
        return 'List plugins';
    }

    public function getDescription(): string
    {
        return 'Lists installed Joomla plugins with group, state, version and whether they are protected (core). '
            . 'Filter by group (e.g. "system", "content"), by enabled flag or by words in the name.';
    }

    public function getRequiredScope(): string
    {
        return 'system:read';
    }

    public function getAccess(): Access
    {
        return Access::Read;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'group' => self::str('Plugin group, e.g. "system".'),
            'enabled' => self::bool('true: only enabled; false: only disabled.'),
            'search' => self::str('Words from the plugin name or element.'),
        ] + self::paging(200));
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $this->admin($context);
        [$limit, $offset] = $context->page($arguments, 50, 200);
        $db = $context->db;

        $where = static function () use ($db, $arguments) {
            $q = $db->createQuery()->from($db->quoteName('#__extensions'))->where($db->quoteName('type') . ' = ' . $db->quote('plugin'));
            if (($group = self::optionalString($arguments, 'group')) !== null && $group !== '') {
                $q->where($db->quoteName('folder') . ' = :folder')->bind(':folder', $group);
            }
            if (isset($arguments['enabled'])) {
                $enabled = $arguments['enabled'] ? 1 : 0;
                $q->where($db->quoteName('enabled') . ' = :enabled')->bind(':enabled', $enabled, ParameterType::INTEGER);
            }
            if (($search = self::optionalString($arguments, 'search')) !== null && trim($search) !== '') {
                $like = '%' . addcslashes(trim($search), '%_\\') . '%';
                $q->where('(' . $db->quoteName('name') . ' LIKE :s1 OR ' . $db->quoteName('element') . ' LIKE :s2)')->bind(':s1', $like)->bind(':s2', $like);
            }

            return $q;
        };

        $total = (int) $db->setQuery($where()->select('COUNT(*)'))->loadResult();
        $query = $where()
            ->select($db->quoteName(['extension_id', 'name', 'element', 'folder', 'enabled', 'protected', 'manifest_cache']))
            ->order($db->quoteName('folder'))
            ->order($db->quoteName('ordering'))
            ->order($db->quoteName('name'));

        $items = [];
        foreach ($db->setQuery($query, $offset, $limit)->loadObjectList() as $p) {
            $items[] = [
                'id' => (int) $p->extension_id,
                'name' => Text::_((string) $p->name),
                'group' => (string) $p->folder,
                'element' => (string) $p->element,
                'enabled' => (bool) $p->enabled,
                'protected' => (bool) $p->protected,
                'version' => (new Registry((string) $p->manifest_cache))->get('version'),
            ];
        }

        return ['total' => $total, 'limit' => $limit, 'offset' => $offset, 'has_more' => $offset + \count($items) < $total, 'items' => $items];
    }
}
