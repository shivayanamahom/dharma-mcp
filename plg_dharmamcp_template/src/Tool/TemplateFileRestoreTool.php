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

final class TemplateFileRestoreTool extends AbstractTemplateTool
{
    public function getName(): string
    {
        return 'template.file_restore';
    }

    public function getTitle(): string
    {
        return 'Restore template file from backup';
    }

    public function getDescription(): string
    {
        return 'Puts a saved earlier version of a template file back in place (use template.file_backups for backup_id). '
            . 'The current version is saved as a new backup first, so a restore can itself be undone. The user must confirm. Needs a Joomla Super User token.';
    }

    public function getRequiredScope(): string
    {
        return 'template:write';
    }

    public function getAccess(): Access
    {
        return Access::Danger;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'template' => self::str('Template folder name; default is the active site template.'),
            'path' => self::str('File path inside the template.'),
            'backup_id' => self::str('Backup id from template.file_backups.'),
        ], ['path', 'backup_id']);
    }

    /** @return array{0: string, 1: string, 2: string, 3: string}  [template, absolute, relative, backupFile] */
    private function prepare(array $arguments, ToolContext $context): array
    {
        $context->requireSuperUser();
        $context->requireCan('core.edit', 'com_templates', 'edit template files');

        $template = $this->templateName($context, $arguments);
        [$absolute, $relative] = self::safePath($template, self::requireString($arguments, 'path'), false);
        $backup = self::backupPath($template, $relative, self::requireString($arguments, 'backup_id'));
        if (!is_file($backup)) {
            throw ToolException::notFound('No such backup for this file.');
        }

        return [$template, $absolute, $relative, $backup];
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        [$template, $absolute, $relative, $backup] = $this->prepare($arguments, $context);

        return [
            'action' => 'Restore file from backup',
            'template' => $template,
            'path' => $relative,
            'backup_id' => (string) $arguments['backup_id'],
            'backup_bytes' => (int) filesize($backup),
            'current_bytes' => is_file($absolute) ? (int) filesize($absolute) : null,
            'note' => 'The current version is saved as a new backup before the restore.',
        ];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        [$template, $absolute, $relative, $backup] = $this->prepare($arguments, $context);

        $content = (string) file_get_contents($backup);
        if (strtolower(pathinfo($relative, PATHINFO_EXTENSION)) === 'php' && ($error = self::phpSyntaxError($content)) !== null) {
            throw ToolException::validation('The backup has a PHP syntax error, nothing was restored: ' . $error);
        }

        $newBackup = self::backup($template, $relative, $absolute);
        self::writeFile($absolute, $content);

        return ['restored' => true, 'template' => $template, 'path' => $relative, 'sha256' => hash('sha256', $content), 'previous_version_backup_id' => $newBackup];
    }
}
