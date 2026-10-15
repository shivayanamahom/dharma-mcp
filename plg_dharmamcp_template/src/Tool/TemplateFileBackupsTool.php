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

final class TemplateFileBackupsTool extends AbstractTemplateTool
{
    public function getName(): string
    {
        return 'template.file_backups';
    }

    public function getTitle(): string
    {
        return 'List file backups';
    }

    public function getDescription(): string
    {
        return 'Lists the saved previous versions of a template file (created automatically before every template.file_write and template.file_restore), newest first. '
            . 'Pass a backup_id to template.file_restore to bring a version back.';
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
            'path' => self::str('File path inside the template.'),
        ], ['path']);
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $context->requireCan('core.manage', 'com_templates', 'view templates');
        $template = $this->templateName($context, $arguments);
        $relative = trim(str_replace('\\', '/', self::requireString($arguments, 'path')), '/');
        // Путь проверяется как для записи: файл мог быть удалён, а копии остались.
        [, $relative] = self::safePath($template, $relative, false);

        $backups = [];
        foreach (self::listBackups($template, $relative) as $id) {
            $file = self::backupPath($template, $relative, $id);
            $backups[] = ['backup_id' => $id, 'bytes' => (int) filesize($file), 'sha256' => hash_file('sha256', $file)];
        }

        return ['template' => $template, 'path' => $relative, 'backups' => $backups];
    }
}
