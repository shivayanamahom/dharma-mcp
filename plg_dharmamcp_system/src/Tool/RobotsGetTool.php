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

use Dharma\Component\DharmaMcp\Site\Support\FileBackups;
use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;

final class RobotsGetTool extends AbstractSystemTool
{
    public function getName(): string
    {
        return 'robots.get';
    }

    public function getTitle(): string
    {
        return 'Read robots.txt';
    }

    public function getDescription(): string
    {
        return 'Reads robots.txt in the site root (rules for search engine crawlers) with its sha256 and the saved earlier versions. '
            . 'Pass the sha256 to robots.set as expected_sha256.';
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
        $file = JPATH_ROOT . '/robots.txt';
        $exists = is_file($file);
        $content = $exists ? (string) file_get_contents($file) : '';

        return [
            'exists' => $exists,
            'bytes' => \strlen($content),
            'sha256' => $exists ? hash('sha256', $content) : null,
            'content' => $context->userContent($content),
            'backups' => FileBackups::ids('site', 'robots.txt'),
        ];
    }
}
