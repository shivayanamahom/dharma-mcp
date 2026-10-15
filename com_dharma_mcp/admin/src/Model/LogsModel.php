<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;

class LogsModel extends ListModel
{
    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'a.id',
                'method', 'a.method',
                'tool_name', 'a.tool_name',
                'status', 'a.status',
                'duration_ms', 'a.duration_ms',
                'created', 'a.created',
            ];
        }

        parent::__construct($config);
    }

    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select([
            $db->quoteName('a.id'),
            $db->quoteName('a.token_id'),
            $db->quoteName('a.user_id'),
            $db->quoteName('a.method'),
            $db->quoteName('a.tool_name'),
            $db->quoteName('a.arguments_json'),
            $db->quoteName('a.status'),
            $db->quoteName('a.duration_ms'),
            $db->quoteName('a.error_message'),
            $db->quoteName('a.ip'),
            $db->quoteName('a.created'),
            $db->quoteName('t.title', 'token_title'),
            $db->quoteName('u.name', 'user_name'),
        ])
        ->from($db->quoteName('#__dharma_mcp_logs', 'a'))
        ->leftJoin($db->quoteName('#__dharma_mcp_tokens', 't'), $db->quoteName('t.id') . ' = ' . $db->quoteName('a.token_id'))
        ->leftJoin($db->quoteName('#__users', 'u'), $db->quoteName('u.id') . ' = ' . $db->quoteName('a.user_id'));

        $search = $this->getState('filter.search');
        if (!empty($search)) {
            $token = '%' . trim($search) . '%';
            $query->where('(' . $db->quoteName('a.tool_name') . ' LIKE :s1 OR ' . $db->quoteName('a.method') . ' LIKE :s2 OR ' . $db->quoteName('a.error_message') . ' LIKE :s3)')
                ->bind(':s1', $token)
                ->bind(':s2', $token)
                ->bind(':s3', $token);
        }

        $status = $this->getState('filter.status');
        if (!empty($status)) {
            $query->where($db->quoteName('a.status') . ' = :status')->bind(':status', $status);
        }

        $orderCol = $this->state->get('list.ordering', 'a.id');
        $orderDirn = $this->state->get('list.direction', 'DESC');
        $query->order($db->escape($orderCol . ' ' . $orderDirn));

        return $query;
    }
}
