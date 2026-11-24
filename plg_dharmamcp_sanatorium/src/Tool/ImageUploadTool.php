<?php
declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Sanatorium\Tool;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ImageStore;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;

\defined('_JEXEC') or die;

final class ImageUploadTool extends AbstractSanatoriumTool
{
    public function getName(): string
    {
        return 'sanatorium.image_upload';
    }

    public function getDescription(): string
    {
        return 'Upload an image from base64 to the sanatorium media folder. Use the resulting path in image fields, the gallery or the health map.';
    }

    public function getRequiredScope(): string
    {
        return 'sanatorium:write';
    }

    public function getAccess(): Access
    {
        return Access::Danger;
    }

    public function getInputSchema(): array
    {
        $properties = ImageStore::inputProperties();
        unset($properties['url']);

        return self::schema($properties, ['base64', 'filename']);
    }

    private function check(array $args, ToolContext $context): array
    {
        $this->boot($context);
        $context->requireCan('core.create', 'com_media', 'upload images');
        $data = self::requireString($args, 'base64');
        if (strlen($data) > 13981016) {
            throw ToolException::validation('The image is larger than 10 MB.');
        }

        return ['base64' => $data, 'filename' => self::requireString($args, 'filename')];
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        $data = $this->check($arguments, $context);
        return ['action' => 'Upload image', 'source' => ImageStore::previewSource($data), 'folder' => 'images/dharma-mcp/sanatorium'];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        return ImageStore::upload($this->check($arguments, $context), 'sanatorium');
    }
}
