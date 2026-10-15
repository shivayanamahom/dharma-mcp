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

final class TemplateFileWriteTool extends AbstractTemplateTool
{
    public function getName(): string
    {
        return 'template.file_write';
    }

    public function getTitle(): string
    {
        return 'Write template file';
    }

    public function getDescription(): string
    {
        return 'Creates or overwrites one text file inside a site template (css, js, php, xml, ini, json, html...). DANGEROUS: PHP files run on the live site. '
            . 'Before writing, PHP/JSON/XML syntax is checked and the previous version is saved as a backup (template.file_backups, template.file_restore). '
            . 'Always read the file first and pass its sha256 as expected_sha256. The user must confirm after seeing the preview. Needs a Joomla Super User token.';
    }

    public function getRequiredScope(): string
    {
        return 'template:write';
    }

    public function getAccess(): Access
    {
        return Access::Danger;
    }

    public function isIdempotent(): bool
    {
        return true;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'template' => self::str('Template folder name; default is the active site template.'),
            'path' => self::str('File path inside the template.'),
            'content' => self::str('The complete new content of the file.'),
            'expected_sha256' => self::str('sha256 of the file as you last read it (from template.file_read). Omit only when creating a new file.'),
        ], ['path', 'content']);
    }

    /** @return array{0: string, 1: string, 2: string, 3: ?string, 4: string}  [template, absolute, relative, currentContent, newContent] */
    private function prepare(array $arguments, ToolContext $context): array
    {
        $context->requireSuperUser();
        $context->requireCan('core.edit', 'com_templates', 'edit template files');

        $template = $this->templateName($context, $arguments);
        [$absolute, $relative] = self::safePath($template, self::requireString($arguments, 'path'), false);

        $new = $arguments['content'] ?? null;
        if (!\is_string($new)) {
            throw ToolException::validation("Parameter 'content' must be a string.");
        }
        if (\strlen($new) > self::MAX_WRITE_BYTES) {
            throw ToolException::validation('The content is larger than ' . self::MAX_WRITE_BYTES . ' bytes.');
        }

        $current = is_file($absolute) ? (string) file_get_contents($absolute) : null;
        $expected = self::optionalString($arguments, 'expected_sha256');
        if ($current !== null && $expected !== null && !hash_equals(hash('sha256', $current), strtolower($expected))) {
            throw ToolException::conflict('The file changed since you read it (sha256 does not match). Read it again with template.file_read and redo your edit.');
        }
        if ($current !== null && $expected === null) {
            throw ToolException::validation('This file already exists: pass expected_sha256 from template.file_read, so newer changes are not overwritten.');
        }

        $this->validateSyntax($relative, $new);

        return [$template, $absolute, $relative, $current, $new];
    }

    private function validateSyntax(string $relative, string $content): void
    {
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

        if ($extension === 'php' && ($error = self::phpSyntaxError($content)) !== null) {
            throw ToolException::validation('PHP syntax error, nothing was written: ' . $error);
        }
        if ($extension === 'json') {
            json_decode($content);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw ToolException::validation('Invalid JSON, nothing was written: ' . json_last_error_msg());
            }
        }
        if ($extension === 'xml' || $extension === 'svg') {
            $previous = libxml_use_internal_errors(true);
            $ok = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET) !== false;
            $message = ($libxmlError = libxml_get_last_error()) ? trim($libxmlError->message) : 'not well-formed';
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if (!$ok) {
                throw ToolException::validation('XML is not well-formed, nothing was written: ' . $message);
            }
        }
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        [$template, , $relative, $current, $new] = $this->prepare($arguments, $context);

        $oldLines = $current === null ? [] : (preg_split('/\R/', $current) ?: []);
        $newLines = preg_split('/\R/', $new) ?: [];
        $removed = array_values(array_diff($oldLines, $newLines));
        $added = array_values(array_diff($newLines, $oldLines));

        return [
            'action' => $current === null ? 'Create file' : 'Overwrite file',
            'template' => $template,
            'path' => $relative,
            'bytes' => ['before' => $current === null ? null : \strlen($current), 'after' => \strlen($new)],
            'lines' => ['before' => $current === null ? null : \count($oldLines), 'after' => \count($newLines)],
            'syntax_check' => 'passed',
            'removed_lines' => \count($removed),
            'added_lines' => \count($added),
            'removed_sample' => \array_slice($removed, 0, 15),
            'added_sample' => \array_slice($added, 0, 15),
            'backup' => $current === null ? 'none (new file)' : 'the current version will be saved first and can be restored',
        ];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        [$template, $absolute, $relative, $current, $new] = $this->prepare($arguments, $context);

        $backupId = self::backup($template, $relative, $absolute);
        self::writeFile($absolute, $new);

        return [
            'written' => true,
            'template' => $template,
            'path' => $relative,
            'bytes' => \strlen($new),
            'sha256' => hash('sha256', $new),
            'backup_id' => $backupId,
            'created' => $current === null,
            'hint' => $backupId ? 'To undo, call template.file_restore with this backup_id.' : null,
        ];
    }
}
