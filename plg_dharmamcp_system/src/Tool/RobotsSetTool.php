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
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;

final class RobotsSetTool extends AbstractSystemTool
{
    private const MAX_BYTES = 50000;

    public function getName(): string
    {
        return 'robots.set';
    }

    public function getTitle(): string
    {
        return 'Write robots.txt';
    }

    public function getDescription(): string
    {
        return 'Replaces robots.txt in the site root, or restores an earlier saved version (restore_backup_id from robots.get). '
            . 'A wrong file can hide the whole site from search engines, so the previous version is saved first and the user must confirm. '
            . 'Read the file with robots.get first and pass its sha256 as expected_sha256. Needs a Joomla Super User token.';
    }

    public function getRequiredScope(): string
    {
        return 'system:write';
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
            'content' => self::str('The complete new robots.txt text.'),
            'restore_backup_id' => self::str('Instead of content: id of an earlier saved version to restore.'),
            'expected_sha256' => self::str('sha256 of the current file from robots.get (required when the file exists).'),
        ]);
    }

    /** @return array{0: ?string, 1: string}  [текущее содержимое, новое содержимое] */
    private function prepare(array $arguments, ToolContext $context): array
    {
        $this->admin($context);
        $file = JPATH_ROOT . '/robots.txt';
        $current = is_file($file) ? (string) file_get_contents($file) : null;

        $restore = self::optionalString($arguments, 'restore_backup_id');
        if ($restore !== null && $restore !== '') {
            $backup = FileBackups::path('site', 'robots.txt', $restore);
            if (!is_file($backup)) {
                throw ToolException::notFound('No such robots.txt backup.');
            }
            $new = (string) file_get_contents($backup);
        } else {
            $new = $arguments['content'] ?? null;
            if (!\is_string($new)) {
                throw ToolException::validation("Pass 'content' (or 'restore_backup_id').");
            }
        }

        if (\strlen($new) > self::MAX_BYTES) {
            throw ToolException::validation('robots.txt is larger than ' . self::MAX_BYTES . ' bytes.');
        }
        $expected = self::optionalString($arguments, 'expected_sha256');
        if ($current !== null) {
            if ($expected === null) {
                throw ToolException::validation('Pass expected_sha256 from robots.get so newer changes are not overwritten.');
            }
            if (!hash_equals(hash('sha256', $current), strtolower($expected))) {
                throw ToolException::conflict('robots.txt changed since you read it. Read it again with robots.get.');
            }
        }

        return [$current, $new];
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        [$current, $new] = $this->prepare($arguments, $context);
        $blocksAll = (bool) preg_match('/^\s*user-agent:\s*\*\s*[\r\n]+\s*disallow:\s*\/\s*$/im', $new);

        return [
            'action' => $current === null ? 'Create robots.txt' : 'Replace robots.txt',
            'bytes' => ['before' => $current === null ? null : \strlen($current), 'after' => \strlen($new)],
            'new_content' => $new,
            'warning' => $blocksAll ? 'These rules block ALL crawlers from the WHOLE site (Disallow: /). Search engines will drop the site.' : null,
            'backup' => $current === null ? 'none (new file)' : 'the current version will be saved first',
        ];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        [$current, $new] = $this->prepare($arguments, $context);
        $file = JPATH_ROOT . '/robots.txt';

        $backupId = FileBackups::create('site', 'robots.txt', $file);
        FileBackups::write($file, $new);

        return ['written' => true, 'bytes' => \strlen($new), 'sha256' => hash('sha256', $new), 'backup_id' => $backupId];
    }
}
