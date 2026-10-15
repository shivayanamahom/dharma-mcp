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

final class TemplateFilesTool extends AbstractTemplateTool
{
    private const MAX_ENTRIES = 500;

    private const SKIP_DIRS = ['node_modules', 'vendor', 'cache'];

    public function getName(): string
    {
        return 'template.files';
    }

    public function getTitle(): string
    {
        return 'List template files';
    }

    public function getDescription(): string
    {
        return 'Lists the text files (php, css, js, xml, ini, json, html, scss...) of a site template, recursively, optionally inside a sub-folder '
            . '(for example "html" to see the layout overrides or "css"). Returns path, size and modification time. '
            . 'Defaults to the template used by the site.';
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
        return self::schema([
            'template' => self::str('Template folder name; default is the active site template.'),
            'folder' => self::str('Sub-folder inside the template, e.g. "html" or "css".'),
        ]);
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $context->requireCan('core.manage', 'com_templates', 'view templates');
        $template = $this->templateName($context, $arguments);

        $base = realpath(JPATH_ROOT . '/templates/' . $template);
        if ($base === false) {
            throw ToolException::notFound('Template folder not found.');
        }

        $root = $base;
        $folder = trim(str_replace('\\', '/', (string) (self::optionalString($arguments, 'folder') ?? '')), '/');
        if ($folder !== '') {
            if (preg_match('#(^|/)\.{1,2}(/|$)#', $folder) || str_contains($folder, "\0")) {
                throw ToolException::validation("The folder must not contain '.' or '..'.");
            }
            $root = realpath($base . '/' . $folder) ?: '';
            if ($root === '' || !is_dir($root) || ($root !== $base && !str_starts_with($root, $base . '/'))) {
                throw ToolException::notFound("Folder '$folder' not found in template '$template'.");
            }
        }

        $files = [];
        $truncated = false;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $f): bool => $f->getFilename()[0] !== '.' && !($f->isDir() && \in_array($f->getFilename(), self::SKIP_DIRS, true)) && !$f->isLink()
            )
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || !\in_array(strtolower($file->getExtension()), self::TEXT_EXTENSIONS, true)) {
                continue;
            }
            if (\count($files) >= self::MAX_ENTRIES) {
                $truncated = true;
                break;
            }
            $files[] = [
                'path' => substr($file->getPathname(), \strlen($base) + 1),
                'bytes' => $file->getSize(),
                'modified' => gmdate('c', $file->getMTime()),
            ];
        }
        usort($files, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return ['template' => $template, 'folder' => $folder ?: null, 'count' => \count($files), 'truncated' => $truncated, 'files' => $files];
    }
}
