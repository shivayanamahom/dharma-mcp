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

use Dharma\Component\DharmaMcp\Site\Support\FileBackups;
use Dharma\Component\DharmaMcp\Site\Tool\AbstractTool;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;

/**
 * Основа инструментов шаблона. Здесь собрана вся защита работы с файлами:
 * только внутри templates/<шаблон>/, без «..» и точечных файлов, только белый список расширений,
 * защита от выхода через символьные ссылки, проверка синтаксиса PHP до записи и резервные копии.
 */
abstract class AbstractTemplateTool extends AbstractTool
{
    /** Расширения, которые можно читать и записывать. */
    protected const TEXT_EXTENSIONS = ['php', 'css', 'js', 'json', 'xml', 'ini', 'html', 'htm', 'txt', 'scss', 'less', 'svg', 'md'];

    protected const MAX_READ_BYTES = 300000;

    protected const MAX_WRITE_BYTES = 500000;

    public function getPack(): string
    {
        return 'template';
    }

    /** Шаблон из аргумента или активный шаблон сайта. Имя проверяется по списку установленных. */
    protected function templateName(ToolContext $context, array $arguments): string
    {
        $db = $context->db;
        $name = self::optionalString($arguments, 'template');

        if ($name === null || $name === '') {
            $query = $db->createQuery()
                ->select($db->quoteName('template'))
                ->from($db->quoteName('#__template_styles'))
                ->where($db->quoteName('client_id') . ' = 0')
                ->where($db->quoteName('home') . ' = ' . $db->quote('1'));
            $name = (string) $db->setQuery($query)->loadResult();
        }

        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $name)) {
            throw ToolException::validation('Invalid template name.');
        }

        $query = $db->createQuery()
            ->select('COUNT(*)')
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('template'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('element') . ' = :name')
            ->bind(':name', $name);
        if ((int) $db->setQuery($query)->loadResult() === 0) {
            throw ToolException::notFound("Site template '$name' is not installed.");
        }

        return $name;
    }

    /**
     * Безопасный абсолютный путь внутри каталога шаблона.
     *
     * @return array{0: string, 1: string}  [абсолютный путь, нормализованный относительный путь]
     */
    protected static function safePath(string $template, string $relative, bool $mustExist, bool $textOnly = true): array
    {
        $base = realpath(JPATH_ROOT . '/templates/' . $template);
        if ($base === false) {
            throw ToolException::notFound("Template folder of '$template' not found.");
        }

        $relative = trim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || str_contains($relative, "\0")) {
            throw ToolException::validation('A file path inside the template is required.');
        }

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw ToolException::validation("The path must not contain '.', '..' or empty segments.");
            }
            if ($segment[0] === '.') {
                throw ToolException::forbidden("Hidden files and folders ('$segment') are not accessible.");
            }
        }

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if ($textOnly && !\in_array($extension, self::TEXT_EXTENSIONS, true)) {
            throw ToolException::forbidden("Files with extension '.$extension' are not accessible. Allowed: " . implode(', ', self::TEXT_EXTENSIONS) . '.');
        }

        $target = $base . '/' . $relative;

        if ($mustExist) {
            $real = realpath($target);
            if ($real === false || !is_file($real)) {
                throw ToolException::notFound("File '$relative' does not exist in template '$template'.");
            }
            if (!str_starts_with($real, $base . '/')) {
                throw ToolException::forbidden('The path leaves the template folder.');
            }

            return [$real, $relative];
        }

        // Новый или перезаписываемый файл: ближайший существующий родитель обязан лежать внутри шаблона.
        $parent = \dirname($target);
        while (!is_dir($parent)) {
            $parent = \dirname($parent);
        }
        $realParent = realpath($parent);
        if ($realParent === false || ($realParent !== $base && !str_starts_with($realParent, $base . '/'))) {
            throw ToolException::forbidden('The path leaves the template folder.');
        }
        if (is_link($target)) {
            throw ToolException::forbidden('Symbolic links cannot be overwritten.');
        }

        return [$target, $relative];
    }

    /** Текст синтаксической ошибки PHP или null. Работает без exec(), поэтому подходит для обычного хостинга. */
    protected static function phpSyntaxError(string $code): ?string
    {
        try {
            token_get_all($code, TOKEN_PARSE);
        } catch (\ParseError $e) {
            return $e->getMessage() . ' (line ' . $e->getLine() . ')';
        }

        return null;
    }

    /** Сохраняет текущий файл как резервную копию; возвращает id копии или null, если файла не было. */
    protected static function backup(string $template, string $relative, string $absolute): ?string
    {
        return FileBackups::create($template, $relative, $absolute);
    }

    /** @return string[]  id копий, новые первыми */
    protected static function listBackups(string $template, string $relative): array
    {
        return FileBackups::ids($template, $relative);
    }

    protected static function backupPath(string $template, string $relative, string $id): string
    {
        return FileBackups::path($template, $relative, $id);
    }

    /** Запись файла с созданием недостающих каталогов внутри шаблона. */
    protected static function writeFile(string $absolute, string $content): void
    {
        FileBackups::write($absolute, $content);
    }
}
