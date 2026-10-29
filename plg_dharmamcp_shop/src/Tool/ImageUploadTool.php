<?php

/**
 * Dharma MCP — набор «Магазин».
 *
 * @package    plg_dharmamcp_shop
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Shop\Tool;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ImageStore;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
use Dharma\Component\DharmaShop\Administrator\Image\ImageUploader;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;

/**
 * Загрузка картинки в папку изображений магазина: по ссылке или из base64. Проверки те же, что при загрузке в админке
 * (тип по содержимому, размер, защита от файлов с PHP-кодом). Возвращает путь, который затем вписывается в товар,
 * категорию, бренд и т. д.
 */
final class ImageUploadTool extends AbstractEntityTool
{
    private const FOLDERS = ['products', 'categories', 'gallery', 'common', 'seo', 'icons'];
    private const MAX_BYTES = 10 * 1048576;

    public function getName(): string
    {
        return 'shop.image_upload';
    }

    public function getTitle(): string
    {
        return 'Upload an image for the shop';
    }

    public function getDescription(): string
    {
        return 'Uploads one image (jpg, png or webp, up to 10 MB) into the shop image folder, either from a public https address ("url") or from base64 text ("base64"). '
            . 'Returns "path" (e.g. images/dharmashop/products/2026/ab12cd.jpg). Use that path in products.create / products.update "gallery" '
            . '({"path": ..., "alt": ...}) or in the "image" field of a category, brand or other record. The file is not shown anywhere until you put it into a record.';
    }

    public function getRequiredScope(): string
    {
        return 'shop:write';
    }

    public function getAccess(): Access
    {
        return Access::Draft;
    }

    public function isIdempotent(): bool
    {
        return false;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'url' => self::str('Public https address of the image. The site downloads it.'),
            'base64' => self::str('The image file as base64 text (use instead of url for small files).'),
            'filename' => self::str('File name with extension, letters, digits, - and _ only, e.g. "red-shirt.jpg". Required with base64; with url it is taken from the address.'),
            'folder' => self::str('Where to put it: products (default), categories, gallery, common, seo, icons.', ['enum' => self::FOLDERS]),
        ]);
    }

    private function folder(array $arguments): string
    {
        return self::oneOf($arguments, 'folder', self::FOLDERS, 'products') ?? 'products';
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        return [
            'action' => 'Upload an image',
            'source' => isset($arguments['url']) ? (string) $arguments['url'] : 'base64 data (' . \strlen((string) ($arguments['base64'] ?? '')) . ' characters)',
            'folder' => $this->folder($arguments),
        ];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $this->services($context);
        $context->requireCan('core.create', 'com_dharmashop', 'upload images');
        $folder = $this->folder($arguments);

        if (!empty($arguments['url'])) {
            [$bytes, $name] = $this->download((string) $arguments['url']);
        } elseif (!empty($arguments['base64'])) {
            $bytes = base64_decode((string) $arguments['base64'], true);
            if ($bytes === false || $bytes === '') {
                throw ToolException::validation("The 'base64' value is not valid base64 text.");
            }
            $name = '';
        } else {
            throw ToolException::validation("Pass either 'url' or 'base64'.");
        }

        $name = self::fileName((string) ($arguments['filename'] ?? $name), $bytes);
        if (\strlen($bytes) > self::MAX_BYTES) {
            throw ToolException::validation('The image is larger than 10 MB.');
        }

        try {
            $uploader = new ImageUploader(ComponentHelper::getParams('com_media'), JPATH_ROOT, ComponentHelper::getParams('com_dharmashop'));
            $stored = $uploader->storeGenerated($bytes, $name, $folder);
        } catch (\RuntimeException $e) {
            throw ToolException::validation('The shop did not accept the image: ' . trim(strip_tags(Text::_($e->getMessage()))) . ' (allowed: jpg, png, webp).');
        }

        return ['uploaded' => true, 'path' => $stored['path'], 'width' => $stored['width'] ?? null, 'height' => $stored['height'] ?? null];
    }

    /** Безопасное имя с допустимым расширением; расширение берётся из содержимого, если имя его не даёт. */
    private static function fileName(string $name, string $bytes): string
    {
        $info = pathinfo(basename(str_replace('\\', '/', $name)));
        $base = strtolower(preg_replace('/[^a-z0-9_-]+/i', '-', (string) ($info['filename'] ?? '')) ?? '');
        $base = trim($base, '-_') ?: 'image';
        $extension = strtolower((string) ($info['extension'] ?? ''));

        if (!\in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? '';
        }
        if ($extension === '') {
            throw ToolException::validation('Only jpg, png and webp images are accepted.');
        }

        return substr($base, 0, 60) . '.' . $extension;
    }

    /**
     * Скачивание по https с защитой от обращения к внутренним адресам: имя узла должно указывать на публичные адреса,
     * перенаправления не выполняются.
     *
     * @return array{0: string, 1: string}  содержимое и имя файла из адреса
     */
    private function download(string $url): array
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw ToolException::validation('The address must be a public https address without a login and a custom port.');
        }

        ImageStore::assertPublicHost((string) $parts['host']);

        try {
            $http = HttpFactory::getHttp(['follow_location' => false, 'timeout' => 15]);
            $response = $http->get($url, ['Accept' => 'image/*'], 15);
        } catch (\Throwable $e) {
            throw ToolException::validation('The image could not be downloaded: ' . mb_substr($e->getMessage(), 0, 160) . '.');
        }

        if ((int) $response->getStatusCode() !== 200) {
            throw ToolException::validation('The image could not be downloaded: the address answered HTTP ' . (int) $response->getStatusCode() . '.');
        }

        return [(string) $response->getBody(), basename((string) ($parts['path'] ?? ''))];
    }
}
