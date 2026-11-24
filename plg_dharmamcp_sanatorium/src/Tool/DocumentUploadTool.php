<?php
declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Sanatorium\Tool;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
use Dharma\Component\DharmaSanatorium\Administrator\Service\DocumentStorage;
use Joomla\CMS\Application\ApplicationHelper;

\defined('_JEXEC') or die;

final class DocumentUploadTool extends AbstractSanatoriumTool
{
    public function getName(): string
    {
        return 'sanatorium.document_upload';
    }

    public function getDescription(): string
    {
        return 'Upload a PDF from base64 to the configured sanatorium documents directory. Requires confirmation. Use the returned path in the files list of a document record.';
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
        return self::schema([
            'filename' => self::str('File name ending in .pdf.'),
            'base64' => self::str('PDF bytes in base64, at most 10 MB before encoding.'),
        ], ['filename', 'base64']);
    }

    private function file(array $args, ToolContext $context): array
    {
        $this->boot($context);
        $context->requireCan('core.create', 'com_media', 'upload documents');
        $storage = new DocumentStorage();
        $limit = min($storage->maxBytes() ?: 10485760, 10485760);
        $name = self::requireString($args, 'filename');
        $encoded = self::requireString($args, 'base64');
        if (strlen($encoded) > 4 * (int) ceil($limit / 3)) {
            throw ToolException::validation('The PDF exceeds the upload limit.');
        }

        $bytes = base64_decode($encoded, true);
        if (!in_array('pdf', $storage->extensions(), true) || !preg_match('/\.pdf$/i', $name)
            || $bytes === false || strlen($bytes) > $limit || !str_starts_with($bytes, '%PDF-')
            || (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== 'application/pdf'
            || preg_match('~<\?(?:php|=)|<script|<html~i', substr($bytes, 0, 4096))) {
            throw ToolException::validation('The file must be a PDF allowed by Joomla media settings.');
        }

        $dir = $storage->directory();
        if (!preg_match('~^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)+$~D', $dir)) {
            throw ToolException::validation('The configured documents directory is invalid.');
        }

        $stem = ApplicationHelper::stringURLSafe(pathinfo($name, PATHINFO_FILENAME)) ?: 'document';

        return [substr($stem, 0, 70), $bytes, $dir];
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        [$stem, $bytes, $dir] = $this->file($arguments, $context);

        return ['action' => 'Upload PDF', 'filename' => $stem . '.pdf', 'bytes' => strlen($bytes), 'directory' => $dir];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        [$stem, $bytes, $dir] = $this->file($arguments, $context);
        $current = JPATH_ROOT;
        foreach (explode('/', $dir) as $part) {
            $current .= '/' . $part;
            if (is_link($current) || (!is_dir($current) && !@mkdir($current, 0755) && !is_dir($current))) {
                throw ToolException::validation('The documents directory could not be created.');
            }
        }

        $path = $dir . '/' . $stem . '-' . bin2hex(random_bytes(8)) . '.pdf';
        $absolute = JPATH_ROOT . '/' . $path;
        if (file_put_contents($absolute, $bytes, LOCK_EX) !== strlen($bytes)) {
            @unlink($absolute);
            throw ToolException::validation('The document could not be saved.');
        }

        return ['uploaded' => true, 'path' => $path, 'bytes' => strlen($bytes)];
    }
}
