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

/**
 * Переопределение (override) — копия штатного макета в templates/<шаблон>/html/, которую затем правят.
 * Сама по себе копия ничего не меняет на сайте, поэтому это «черновой» инструмент.
 */
final class TemplateOverrideCreateTool extends AbstractTemplateTool
{
    public function getName(): string
    {
        return 'template.override_create';
    }

    public function getTitle(): string
    {
        return 'Create layout override';
    }

    public function getDescription(): string
    {
        return 'Copies a stock Joomla layout into the template\'s html/ folder so it can be customised (a layout override). '
            . 'kind "component": a component view layout (component, view, layout); kind "module": a module layout (module, layout); '
            . 'kind "layout": a shared layout (layout_path such as "joomla/content/info_block"). The copy is identical to the original until you edit it '
            . 'with template.file_write. Fails if the override already exists. Needs a Joomla Super User token.';
    }

    public function getRequiredScope(): string
    {
        return 'template:write';
    }

    public function getAccess(): Access
    {
        return Access::Draft;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'template' => self::str('Template folder name; default is the active site template.'),
            'kind' => ['type' => 'string', 'enum' => ['component', 'module', 'layout'], 'description' => 'What to override.'],
            'component' => self::str('For kind "component": e.g. com_content.'),
            'view' => self::str('For kind "component": e.g. article.'),
            'module' => self::str('For kind "module": e.g. mod_menu.'),
            'layout' => self::str('Layout file name without .php (default "default").'),
            'layout_path' => self::str('For kind "layout": e.g. joomla/content/info_block.'),
        ], ['kind']);
    }

    private static function name(array $arguments, string $key, string $pattern = '/^[A-Za-z0-9_\-]+$/'): string
    {
        $value = self::requireString($arguments, $key);
        if (!preg_match($pattern, $value)) {
            throw ToolException::validation("Parameter '$key' has invalid characters.");
        }

        return $value;
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $context->requireSuperUser();
        $context->requireCan('core.edit', 'com_templates', 'edit templates');
        $template = $this->templateName($context, $arguments);
        $kind = self::oneOf($arguments, 'kind', ['component', 'module', 'layout']);
        $layout = isset($arguments['layout']) ? self::name($arguments, 'layout') : 'default';

        switch ($kind) {
            case 'component':
                $component = self::name($arguments, 'component');
                $view = self::name($arguments, 'view');
                $candidates = [
                    JPATH_ROOT . "/components/$component/tmpl/$view/$layout.php",
                    JPATH_ROOT . "/components/$component/views/$view/tmpl/$layout.php",
                ];
                $target = "html/$component/$view/$layout.php";
                $allowedRoot = JPATH_ROOT . '/components';
                break;
            case 'module':
                $module = self::name($arguments, 'module');
                $candidates = [JPATH_ROOT . "/modules/$module/tmpl/$layout.php"];
                $target = "html/$module/$layout.php";
                $allowedRoot = JPATH_ROOT . '/modules';
                break;
            default:
                $path = self::name($arguments, 'layout_path', '#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*$#');
                $candidates = [JPATH_ROOT . "/layouts/$path.php"];
                $target = "html/layouts/$path.php";
                $allowedRoot = JPATH_ROOT . '/layouts';
        }

        $source = null;
        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real !== false && str_starts_with($real, realpath($allowedRoot) . '/')) {
                $source = $real;
                break;
            }
        }
        if ($source === null) {
            throw ToolException::notFound('The stock layout to copy was not found.');
        }

        [$absolute, $relative] = self::safePath($template, $target, false);
        if (is_file($absolute)) {
            throw ToolException::conflict("The override '$relative' already exists. Edit it with template.file_read and template.file_write.");
        }

        self::writeFile($absolute, (string) file_get_contents($source));

        return [
            'created' => true,
            'template' => $template,
            'path' => $relative,
            'copied_from' => substr($source, \strlen(JPATH_ROOT) + 1),
            'note' => 'The override is identical to the stock layout. Edit it with template.file_read and template.file_write.',
        ];
    }
}
