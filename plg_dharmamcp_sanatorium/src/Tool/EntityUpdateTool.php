<?php
declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Sanatorium\Tool;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;

\defined('_JEXEC') or die;

final class EntityUpdateTool extends AbstractRecordTool
{
    public function getName(): string
    {
        return 'sanatorium.entity_update';
    }

    public function getDescription(): string
    {
        return 'Patch the specified fields of a record, including SEO, pricing, publication or review moderation. Repeatable fields replace the list; grouped parameters merge by key.';
    }

    public function getRequiredScope(): string
    {
        return 'sanatorium:write';
    }

    public function getAccess(): Access
    {
        return Access::Write;
    }

    public function getInputSchema(): array
    {
        return self::schema(['entity' => self::entity(), 'id' => self::int('Record id.'), 'data' => ['type' => 'object', 'description' => 'Only the fields to change.']], ['entity', 'id', 'data']);
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        return $this->change($arguments, $context, false, true);
    }

    public function needsConfirmation(array $arguments, ToolContext $context): bool
    {
        return in_array($arguments['entity'] ?? '', ['site_form', 'lead', 'review'], true);
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        return $this->change($arguments, $context, false, false);
    }
}
