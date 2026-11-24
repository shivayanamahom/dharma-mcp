<?php
declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Sanatorium\Tool;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;

\defined('_JEXEC') or die;

final class ReferenceTool extends AbstractSanatoriumTool
{
    public function getName(): string
    {
        return 'sanatorium.reference';
    }

    public function getDescription(): string
    {
        return 'List active room occupancy codes used as occupancy_id in stay prices. Discover room and service ids using sanatorium.entity_list.';
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
        return self::schema();
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $this->boot($context);
        $db = $context->db;
        $query = $db->createQuery()->select($db->quoteName(['id', 'code', 'title', 'guests', 'is_extra']))
            ->from($db->quoteName('#__dharmasanatorium_occupancies'))
            ->where($db->quoteName('state') . ' = 1')
            ->order($db->quoteName('ordering') . ' ASC, ' . $db->quoteName('id') . ' ASC');
        $rows = $db->setQuery($query)->loadAssocList();
        foreach ($rows as &$row) {
            $row['title'] = $context->userContent((string) $row['title']);
        }
        unset($row);

        return ['occupancies' => $rows];
    }
}
