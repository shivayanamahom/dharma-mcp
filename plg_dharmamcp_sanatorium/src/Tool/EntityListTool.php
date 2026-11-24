<?php
declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Sanatorium\Tool;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;

\defined('_JEXEC') or die;

final class EntityListTool extends AbstractRecordTool
{
    public function getName(): string
    {
        return 'sanatorium.entity_list';
    }

    public function getDescription(): string
    {
        return 'List sanatorium records by type with pagination and optional state or title filters. Lead and review listings contain operational metadata.';
    }

    public function getRequiredScope(): string
    {
        return 'sanatorium:read';
    }

    public function getAccess(): Access
    {
        return Access::Read;
    }

    public function getInputSchema(): array
    {
        return self::schema(self::paging() + [
            'entity' => self::entity(),
            'status' => self::int('Exact publication state or review status. Omit to include all states.'),
            'search' => self::str('Search by title, where available.'),
        ], ['entity']);
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        return $this->rows($arguments, $this->definition($arguments, $context), $context);
    }
}
