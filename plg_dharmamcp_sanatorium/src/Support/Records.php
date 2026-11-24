<?php
declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Sanatorium\Support;

use Dharma\Component\DharmaMcp\Site\Tool\ToolException;

\defined('_JEXEC') or die;

final class Records
{
    private const DEFINITIONS = [
        'direction' => ['Direction', 'directions', 'title,alias,parent_id,level,state', true],
        'service_group' => ['Servicegroup', 'service_groups', 'title,alias,parent_id,level,kind,state', true],
        'service' => ['Service', 'services', 'title,alias,group_id,state,price_from'],
        'program' => ['Program', 'programs', 'title,alias,kind,state,price_from'],
        'offer' => ['Offer', 'offers', 'title,alias,kind,state,price_from'],
        'room' => ['Room', 'room_categories', 'title,alias,state'],
        'amenity' => ['Amenity', 'amenities', 'title,code,state'],
        'document' => ['Document', 'documents', 'title,code,state'],
        'section_page' => ['Sectionpage', 'section_pages', 'title,code,state'],
        'question' => ['Question', 'questions', 'title,state,lead_id'],
        'site_form' => ['Siteform', 'forms', 'title,code,kind,state'],
        'lead_status' => ['Leadstatus', 'lead_statuses', 'title,code,state'],
        'lead' => ['Lead', 'leads', 'kind,status_id,assigned_to,form_id,state,created'],
        'review' => ['Review', 'reviews', 'object_type,object_id,rating,status,source,created'],
    ];

    public static function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function get(string $type): array
    {
        if (!isset(self::DEFINITIONS[$type])) {
            throw ToolException::validation('Unknown record type.', ['allowed' => self::keys()]);
        }
        [$model, $table, $columns] = self::DEFINITIONS[$type];

        return [
            'type' => $type,
            'model' => $model,
            'table' => '#__dharmasanatorium_' . $table,
            'columns' => array_merge(['id'], explode(',', $columns)),
            'tree' => self::DEFINITIONS[$type][3] ?? false,
            'state' => $type === 'review' ? 'status' : 'state',
        ];
    }
}
