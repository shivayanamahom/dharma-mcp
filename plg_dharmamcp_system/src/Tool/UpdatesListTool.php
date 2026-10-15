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
use Joomla\Registry\Registry;

final class UpdatesListTool extends AbstractSystemTool
{
    public function getName(): string
    {
        return 'updates.list';
    }

    public function getTitle(): string
    {
        return 'Available updates';
    }

    public function getDescription(): string
    {
        return 'Lists extension and Joomla updates that Joomla has already found (it does not contact update servers itself): '
            . 'name, installed version, available version. If the list is empty the check may simply not have run recently in the Joomla admin.';
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
        return self::schema();
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $this->admin($context);
        $db = $context->db;

        $query = $db->createQuery()
            ->select(['u.name', 'u.type', 'u.element', 'u.folder', 'u.version AS available', 'u.infourl', 'e.manifest_cache'])
            ->from($db->quoteName('#__updates', 'u'))
            ->join('LEFT', $db->quoteName('#__extensions', 'e') . ' ON e.extension_id = u.extension_id')
            ->order($db->quoteName('u.name'));

        $items = [];
        foreach ($db->setQuery($query)->loadObjectList() as $u) {
            $items[] = [
                'name' => Text::_((string) $u->name),
                'type' => (string) $u->type,
                'element' => (string) $u->element,
                'installed' => $u->manifest_cache ? (new Registry((string) $u->manifest_cache))->get('version') : null,
                'available' => (string) $u->available,
                'info' => (string) $u->infourl,
            ];
        }

        return ['count' => \count($items), 'updates' => $items];
    }
}
