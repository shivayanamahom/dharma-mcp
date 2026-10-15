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
use Joomla\CMS\Table\Style;
use Joomla\Registry\Registry;

final class TemplateStyleUpdateTool extends AbstractTemplateTool
{
    public function getName(): string
    {
        return 'template.style_update';
    }

    public function getTitle(): string
    {
        return 'Change template style parameters';
    }

    public function getDescription(): string
    {
        return 'Changes parameters of a template style (colours, logo, layout options) and optionally its title. '
            . 'Only parameters declared by the template are accepted (see template.style_get); values are merged into the existing ones. '
            . 'If the style is the active one, visitors see the change at once, so the user must confirm.';
    }

    public function getRequiredScope(): string
    {
        return 'template:write';
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
            'id' => self::int('Style id (see template.styles).'),
            'params' => ['type' => 'object', 'description' => 'Parameter names and new values; only declared parameters.'],
            'title' => self::str('New style title (optional).'),
        ], ['id']);
    }

    /** @return array{params: array<string, mixed>, title: ?string} */
    private function changes(ToolContext $context, array $arguments, object $style): array
    {
        $params = $arguments['params'] ?? [];
        if (!\is_array($params)) {
            throw ToolException::validation("Parameter 'params' must be an object.");
        }
        $title = self::optionalString($arguments, 'title');
        if ($params === [] && ($title === null || trim($title) === '')) {
            throw ToolException::validation('Pass params and/or title to change.');
        }

        $declared = TemplateStyleGetTool::declaredParams($context, (string) $style->template);
        $unknown = array_values(array_diff(array_map('strval', array_keys($params)), array_keys($declared)));
        if ($unknown !== []) {
            throw ToolException::validation(
                'Unknown template parameters: ' . implode(', ', $unknown) . '.',
                ['declared' => array_keys($declared)]
            );
        }
        foreach ($params as $name => $value) {
            if (!\is_scalar($value) && $value !== null) {
                throw ToolException::validation("Parameter '$name' must be a string, number or boolean.");
            }
            $options = $declared[(string) $name]['options'] ?? null;
            if ($options && !\in_array((string) $value, $options, true)) {
                throw ToolException::validation("Parameter '$name' must be one of: " . implode(', ', $options) . '.', ['allowed' => $options]);
            }
        }

        return ['params' => $params, 'title' => $title !== null && trim($title) !== '' ? trim($title) : null];
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        $context->requireCan('core.edit', 'com_templates', 'edit template styles');
        $style = TemplateStyleGetTool::style($context, self::requireInt($arguments, 'id'));
        $changes = $this->changes($context, $arguments, $style);
        $current = (new Registry((string) $style->params))->toArray();

        $diff = [];
        foreach ($changes['params'] as $name => $new) {
            $diff[$name] = ['from' => $current[$name] ?? null, 'to' => $new];
        }

        return [
            'action' => 'Change template style',
            'style' => ['id' => (int) $style->id, 'template' => (string) $style->template, 'title' => (string) $style->title, 'active_on_site' => (string) $style->home === '1'],
            'params' => $diff,
            'title' => $changes['title'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $context->requireCan('core.edit', 'com_templates', 'edit template styles');
        $style = TemplateStyleGetTool::style($context, self::requireInt($arguments, 'id'));
        $changes = $this->changes($context, $arguments, $style);

        $table = new Style($context->db);
        if (!$table->load((int) $style->id)) {
            throw ToolException::notFound('Template style not found.');
        }

        $registry = new Registry((string) $table->params);
        foreach ($changes['params'] as $name => $value) {
            $registry->set((string) $name, $value);
        }
        $data = ['params' => $registry->toString()];
        if ($changes['title'] !== null) {
            $data['title'] = $changes['title'];
        }

        if (!$table->bind($data) || !$table->check() || !$table->store()) {
            throw ToolException::validation('Joomla could not save the template style.');
        }

        return ['updated' => true, 'id' => (int) $style->id, 'changed_params' => array_keys($changes['params'])];
    }
}
