<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Site\Support;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Tool\ToolException;

/**
 * Резервные копии файлов, которые меняют инструменты (шаблон, robots.txt).
 * Хранятся в tmp/dharma_mcp_backups/<область>/<путь>/<метка>.bak за закрытым от веба каталогом.
 */
final class FileBackups
{
    public const KEEP = 30;

    public static function root(): string
    {
        $dir = JPATH_ROOT . '/tmp/dharma_mcp_backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
            @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            @file_put_contents($dir . '/index.html', '<!DOCTYPE html><title></title>');
        }

        return $dir;
    }

    private static function dir(string $scope, string $relative): string
    {
        return self::root() . '/' . $scope . '/' . str_replace('/', '__', $relative);
    }

    /** Сохраняет текущий файл; возвращает id копии или null, если файла не было. */
    public static function create(string $scope, string $relative, string $absolute): ?string
    {
        if (!is_file($absolute)) {
            return null;
        }

        $id = gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
        $dir = self::dir($scope, $relative);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw ToolException::validation('Could not create the backup folder; nothing was changed.');
        }
        if (!@copy($absolute, $dir . '/' . $id . '.bak')) {
            throw ToolException::validation('Could not write the backup copy; nothing was changed.');
        }

        $copies = glob($dir . '/*.bak') ?: [];
        sort($copies);
        foreach (\array_slice($copies, 0, max(0, \count($copies) - self::KEEP)) as $old) {
            @unlink($old);
        }

        return $id;
    }

    /** @return string[]  id копий, новые первыми */
    public static function ids(string $scope, string $relative): array
    {
        $ids = array_map(static fn (string $f): string => basename($f, '.bak'), glob(self::dir($scope, $relative) . '/*.bak') ?: []);
        rsort($ids);

        return $ids;
    }

    public static function path(string $scope, string $relative, string $id): string
    {
        if (!preg_match('/^\d{8}-\d{6}-[0-9a-f]{4}$/', $id)) {
            throw ToolException::validation('Invalid backup id.');
        }

        return self::dir($scope, $relative) . '/' . $id . '.bak';
    }

    /** Запись файла с созданием каталогов; сбрасывает opcache. */
    public static function write(string $absolute, string $content): void
    {
        $dir = \dirname($absolute);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw ToolException::validation('Could not create the folder for the file.');
        }
        if (@file_put_contents($absolute, $content, LOCK_EX) === false) {
            throw ToolException::validation('Could not write the file (check file permissions on the server).');
        }
        if (\function_exists('opcache_invalidate')) {
            @opcache_invalidate($absolute, true);
        }
    }
}
