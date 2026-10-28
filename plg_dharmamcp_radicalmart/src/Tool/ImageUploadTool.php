<?php
/** Dharma MCP — инструменты RadicalMart. */
declare(strict_types=1);
namespace Dharma\Plugin\DharmaMcp\Radicalmart\Tool;
\defined('_JEXEC') or die;
use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ImageStore;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
final class ImageUploadTool extends AbstractRadicalmartTool
{
    public function getName(): string { return 'radicalmart.image_upload'; }
    public function getTitle(): string { return 'Upload an image for RadicalMart'; }
    public function getDescription(): string { return 'Uploads one image (jpg, png or webp, up to 10 MB) into images/dharma-mcp/radicalmart, either from a public https address ("url") or from base64 text ("base64"). Returns "path" (e.g. images/dharma-mcp/radicalmart/2026/ab12cd34-photo.jpg). Put that path into the gallery of a product or category through radicalmart.entity_create / radicalmart.entity_update: data.media.gallery = [{"type": "image", "src": "<path>", "alt": "..."}]. The file is not shown anywhere until you put it into a record.'; }
    public function getRequiredScope(): string { return 'radicalmart:write'; }
    public function getAccess(): Access { return Access::Draft; }
    public function isIdempotent(): bool { return false; }
    public function getInputSchema(): array { return self::schema(ImageStore::inputProperties()); }
    public function preview(array $arguments, ToolContext $context): ?array
    {
        return ['action' => 'Upload an image', 'source' => ImageStore::previewSource($arguments), 'folder' => 'images/dharma-mcp/radicalmart'];
    }
    public function execute(array $arguments, ToolContext $context): array
    {
        $this->prepare($context);
        $context->requireCan('core.create', 'com_media', 'upload images');
        return ImageStore::upload($arguments, 'radicalmart');
    }
}
