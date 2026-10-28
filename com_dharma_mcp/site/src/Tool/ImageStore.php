<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Site\Tool;

\defined('_JEXEC') or die;

use Joomla\CMS\Http\HttpFactory;

/**
 * Загрузка картинки в папку изображений сайта для наборов, которые не зависят от магазина (материалы, RadicalMart).
 * Принимает https-адрес или base64, проверяет тип по содержимому, размер и признаки файлов с кодом, кладёт файл в
 * images/dharma-mcp/<набор>/<год>/ и возвращает путь для вставки в запись. Файл нигде не показывается, пока путь не вписан.
 */
final class ImageStore
{
    public const MAX_BYTES = 10 * 1048576;

    /** Максимум по одной стороне и по площади: защита от «бомб распаковки». */
    private const MAX_SIDE = 12000;
    private const MAX_PIXELS = 50000000;

    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** Расширения, которые принимаются как ссылка на уже лежащую на сайте картинку. */
    private const EXISTING = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];

    /** Свойства входной схемы, одинаковые у всех инструментов загрузки. */
    public static function inputProperties(): array
    {
        return [
            'url' => ['type' => 'string', 'description' => 'Public https address of the image (jpg, png or webp, up to 10 MB). The site downloads it.'],
            'base64' => ['type' => 'string', 'description' => 'The image file as base64 text (use instead of url for small files).'],
            'filename' => ['type' => 'string', 'description' => 'File name with extension, letters, digits, - and _ only, e.g. "red-shirt.jpg". Required with base64; with url it is taken from the address.'],
        ];
    }

    public static function previewSource(array $arguments): string
    {
        return isset($arguments['url']) ? (string) $arguments['url'] : 'base64 data (' . \strlen((string) ($arguments['base64'] ?? '')) . ' characters)';
    }

    /**
     * Принимает аргументы инструмента, получает файл, проверяет и сохраняет.
     *
     * @param   string  $folder  Подпапка внутри images/dharma-mcp (articles, radicalmart).
     *
     * @return array{uploaded: bool, path: string, width: int, height: int, bytes: int}
     */
    public static function upload(array $arguments, string $folder): array
    {
        if (!empty($arguments['url'])) {
            [$bytes, $name] = self::download((string) $arguments['url']);
        } elseif (!empty($arguments['base64'])) {
            $bytes = base64_decode((string) $arguments['base64'], true);
            if ($bytes === false || $bytes === '') {
                throw ToolException::validation("The 'base64' value is not valid base64 text.");
            }
            $name = '';
        } else {
            throw ToolException::validation("Pass either 'url' or 'base64'.");
        }

        if (\strlen($bytes) > self::MAX_BYTES) {
            throw ToolException::validation('The image is larger than 10 MB.');
        }

        [$extension, $width, $height] = self::inspect($bytes);
        $base = self::baseName((string) ($arguments['filename'] ?? $name));
        $relative = 'images/dharma-mcp/' . $folder . '/' . date('Y') . '/' . bin2hex(random_bytes(4)) . '-' . $base . '.' . $extension;
        $absolute = JPATH_ROOT . '/' . $relative;
        $directory = \dirname($absolute);

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw ToolException::validation('The image folder could not be created on the site.');
        }
        if (@file_put_contents($absolute, $bytes, LOCK_EX) !== \strlen($bytes)) {
            @unlink($absolute);
            throw ToolException::validation('The image could not be saved on the site.');
        }
        @chmod($absolute, 0644);

        return ['uploaded' => true, 'path' => $relative, 'width' => $width, 'height' => $height, 'bytes' => \strlen($bytes)];
    }

    /**
     * Проверяет путь уже лежащей на сайте картинки (из загрузки или из менеджера медиа) и возвращает его без суффиксов
     * вида «#joomlaImage://…». Пустая строка допустима — она убирает картинку.
     */
    public static function existingPath(string $value): string
    {
        $path = trim((string) strtok($value, '#'));
        if ($path === '') {
            return '';
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (
            !preg_match('#^images/[A-Za-z0-9_\-./ ]+$#D', $path)
            || str_contains($path, '..')
            || str_contains($path, '//')
            || !\in_array($extension, self::EXISTING, true)
        ) {
            throw ToolException::validation('The image path must be a file under images/, e.g. "images/dharma-mcp/articles/2026/ab12cd34-photo.jpg" (use media.image_upload to get one).');
        }

        $real = realpath(JPATH_ROOT . '/' . $path);
        $root = realpath(JPATH_ROOT . '/images');
        if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || !is_file($real)) {
            throw ToolException::validation("The image '$path' does not exist on the site. Upload it first.");
        }

        return $path;
    }

    /** @return array{0: string, 1: int, 2: int}  расширение, ширина, высота */
    private static function inspect(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);
        $mime = \is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (!isset(self::TYPES[$mime])) {
            throw ToolException::validation('Only jpg, png and webp images are accepted.');
        }

        // Расширение берётся из содержимого, а не из имени, а файл с исполняемым кодом внутри картинки отклоняется.
        if (preg_match('/<\?(?:php|=)|<script/i', $bytes) === 1) {
            throw ToolException::validation('The file contains code and is refused.');
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        if ($width < 1 || $height < 1 || $width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            throw ToolException::validation('The image dimensions are out of range.');
        }

        return [self::TYPES[$mime], $width, $height];
    }

    /** Безопасная основа имени: латиница, цифры, «-» и «_». */
    private static function baseName(string $name): string
    {
        $info = pathinfo(basename(str_replace('\\', '/', $name)));
        $base = strtolower(preg_replace('/[^a-z0-9_-]+/i', '-', (string) ($info['filename'] ?? '')) ?? '');
        $base = trim($base, '-_');

        return substr($base !== '' ? $base : 'image', 0, 60);
    }

    /**
     * Скачивание по https с защитой от обращения к внутренним адресам: имя узла должно указывать на публичные адреса,
     * перенаправления не выполняются.
     *
     * @return array{0: string, 1: string}  содержимое и имя файла из адреса
     */
    private static function download(string $url): array
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw ToolException::validation('The address must be a public https address without a login and a custom port.');
        }

        $ips = gethostbynamel((string) $parts['host']) ?: [];
        if ($ips === []) {
            throw ToolException::validation('The address could not be resolved.');
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw ToolException::validation('The address points to a private network and is refused.');
            }
        }

        try {
            $response = HttpFactory::getHttp(['follow_location' => false, 'timeout' => 15])->get($url, ['Accept' => 'image/*'], 15);
        } catch (\Throwable $e) {
            throw ToolException::validation('The image could not be downloaded: ' . mb_substr($e->getMessage(), 0, 160) . '.');
        }

        if ((int) $response->getStatusCode() !== 200) {
            throw ToolException::validation('The image could not be downloaded: the address answered HTTP ' . (int) $response->getStatusCode() . '.');
        }

        return [(string) $response->getBody(), basename((string) ($parts['path'] ?? ''))];
    }
}
