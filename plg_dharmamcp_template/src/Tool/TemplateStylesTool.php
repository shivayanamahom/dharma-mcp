<?php

/**
 * Dharma MCP — набор «Шаблон».
 *
 * @package    plg_dharmamcp_template
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Template\Tool;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;

final class TemplateStylesTool extends AbstractTemplateTool
{
    public function getName(): string
    {
        return 'template.styles';
    }

    public function getTitle(): string
    {
        return 'List template styles';
    }

    public function getDescription(): string
    {
        return 'Lists the styles of the site templates (a style is a named set of template parameters such as colours and logo). '
            . 'The style marked home is the one used by the public site. Use template.style_get to see parameters.';
    }

    public function getRequiredScope(): string
    {
        return 'template:read';
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
        $context->requireCan('core.manage', 'com_templates', 'view templates');
        $db = $context->db;
        $query = $db->createQuery()
            ->select($db->quoteName(['id', 'template', 'title', 'home']))
            ->from($db->quoteName('#__template_styles'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->order($db->quoteName('template'))
            ->order($db->quoteName('id'));

        $styles = [];
        foreach ($db->setQuery($query)->loadObjectList() as $s) {
            $styles[] = ['id' => (int) $s->id, 'template' => (string) $s->template, 'title' => (string) $s->title, 'home' => $s->home === '1' || $s->home === 1];
        }

        return ['styles' => $styles];
    }
}
