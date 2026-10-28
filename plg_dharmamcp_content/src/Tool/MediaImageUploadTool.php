<?php

/**
 * Dharma MCP — набор «Материалы и меню».
 *
 * @package    plg_dharmamcp_content
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Content\Tool;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ImageStore;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;

/**
 * Загрузка картинки для материалов: по ссылке или из base64. Путь из ответа вписывается в articles.create / articles.update
 * (image_intro, image_fulltext) или в тег <img> текста. Право то же, что у менеджера медиа Joomla: core.create на com_media.
 */
final class MediaImageUploadTool extends AbstractContentTool
{
    public function getName(): string
    {
        return 'media.image_upload';
    }

    public function getTitle(): string
    {
        return 'Upload an image for articles';
    }

    public function getDescription(): string
    {
        return 'Uploads one image (jpg, png or webp, up to 10 MB) into the site image folder images/dharma-mcp/articles, either from a public https address ("url") or from base64 text ("base64"). '
            . 'Returns "path" (e.g. images/dharma-mcp/articles/2026/ab12cd34-photo.jpg). Use that path in articles.create / articles.update as "image_intro" or "image_fulltext", '
            . 'or in an <img src="..."> tag of the article text. The file is not shown anywhere until you put it into an article.';
    }

    public function getRequiredScope(): string
    {
        return 'content:write';
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
        return self::schema(ImageStore::inputProperties());
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        return ['action' => 'Upload an image', 'source' => ImageStore::previewSource($arguments), 'folder' => 'images/dharma-mcp/articles'];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $context->requireCan('core.create', 'com_media', 'upload images');

        return ImageStore::upload($arguments, 'articles');
    }
}
