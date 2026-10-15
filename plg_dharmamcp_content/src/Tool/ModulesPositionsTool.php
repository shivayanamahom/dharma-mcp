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

final class ModulesPositionsTool extends AbstractContentTool
{
    public function getName(): string
    {
        return 'modules.positions';
    }

    public function getTitle(): string
    {
        return 'Template positions';
    }

    public function getDescription(): string
    {
        return 'Lists the module positions of the active site template and the positions already used by modules, with how many modules sit in each. '
            . 'Use it to choose a position for modules.create_custom or modules.update.';
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
        return self::schema();
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $context->requireCan('core.manage', 'com_modules', 'view modules');
        $db = $context->db;

        // Позиции, которые используются модулями сайта.
        $query = $db->createQuery()
            ->select([$db->quoteName('position'), 'COUNT(*) AS modules'])
            ->from($db->quoteName('#__modules'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('published') . ' IN (0, 1)')
            ->group($db->quoteName('position'));
        $used = [];
        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $used[(string) $row->position] = (int) $row->modules;
        }

        // Позиции, объявленные активным шаблоном сайта в templateDetails.xml.
        $query = $db->createQuery()
            ->select($db->quoteName('template'))
            ->from($db->quoteName('#__template_styles'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('home') . ' = ' . $db->quote('1'));
        $template = (string) $db->setQuery($query)->loadResult();

        $declared = [];
        $xmlFile = JPATH_ROOT . '/templates/' . $template . '/templateDetails.xml';
        if ($template !== '' && is_file($xmlFile) && ($xml = simplexml_load_file($xmlFile)) && isset($xml->positions->position)) {
            foreach ($xml->positions->position as $position) {
                $declared[] = (string) $position;
            }
        }

        $positions = [];
        foreach (array_unique(array_merge($declared, array_keys($used))) as $name) {
            $positions[] = ['position' => $name === '' ? '(none)' : $name, 'declared_by_template' => \in_array($name, $declared, true), 'modules' => $used[$name] ?? 0];
        }

        return ['template' => $template, 'positions' => $positions];
    }
}
