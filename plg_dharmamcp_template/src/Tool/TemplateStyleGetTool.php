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
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
use Joomla\CMS\Language\Text;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

final class TemplateStyleGetTool extends AbstractTemplateTool
{
    public function getName(): string
    {
        return 'template.style_get';
    }

    public function getTitle(): string
    {
        return 'Get template style';
    }

    public function getDescription(): string
    {
        return 'Returns one template style: its current parameter values and the list of parameters the template declares '
            . '(name, type, label, default, allowed options). Only declared parameters can be changed with template.style_update.';
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
        return self::schema(['id' => self::int('Style id (see template.styles).')], ['id']);
    }

    /** Строка стиля сайта. */
    public static function style(ToolContext $context, int $id): object
    {
        $db = $context->db;
        $query = $db->createQuery()
            ->select('*')
            ->from($db->quoteName('#__template_styles'))
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('client_id') . ' = 0')
            ->bind(':id', $id, ParameterType::INTEGER);
        $style = $db->setQuery($query)->loadObject();
        if (!$style) {
            throw ToolException::notFound("Template style $id not found.");
        }

        return $style;
    }

    /**
     * Параметры, объявленные шаблоном в templateDetails.xml.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function declaredParams(ToolContext $context, string $template): array
    {
        $file = JPATH_ROOT . '/templates/' . $template . '/templateDetails.xml';
        if (!is_file($file) || !($xml = simplexml_load_file($file))) {
            return [];
        }
        $context->app->getLanguage()->load('tpl_' . $template, JPATH_SITE);
        $context->app->getLanguage()->load('tpl_' . $template . '.sys', JPATH_SITE);

        $declared = [];
        foreach ($xml->xpath('//config//fields[@name="params"]//field') ?: [] as $field) {
            $name = (string) $field['name'];
            if ($name === '' || (string) $field['type'] === 'spacer' || (string) $field['type'] === 'note') {
                continue;
            }
            $options = [];
            foreach ($field->option as $option) {
                $options[] = (string) $option['value'];
            }
            $declared[$name] = array_filter([
                'name' => $name,
                'type' => (string) $field['type'],
                'label' => Text::_((string) $field['label']),
                'default' => isset($field['default']) ? (string) $field['default'] : null,
                'options' => $options ?: null,
            ], static fn ($v): bool => $v !== null);
        }

        return $declared;
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $context->requireCan('core.manage', 'com_templates', 'view templates');
        $style = self::style($context, self::requireInt($arguments, 'id'));

        return [
            'id' => (int) $style->id,
            'template' => (string) $style->template,
            'title' => (string) $style->title,
            'home' => $style->home === '1' || $style->home === 1,
            'params' => (new Registry((string) $style->params))->toArray(),
            'declared_params' => array_values(self::declaredParams($context, (string) $style->template)),
        ];
    }
}
