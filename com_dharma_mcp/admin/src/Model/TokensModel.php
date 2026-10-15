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

class TokensModel extends ListModel
{
    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'a.id',
                'title', 'a.title',
                'state', 'a.state',
                'last_used_at', 'a.last_used_at',
                'requests_count', 'a.requests_count',
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
            $db->quoteName('a.title'),
            $db->quoteName('a.user_id'),
            $db->quoteName('a.token_prefix'),
            $db->quoteName('a.scopes'),
            $db->quoteName('a.state'),
            $db->quoteName('a.expires_at'),
            $db->quoteName('a.last_used_at'),
            $db->quoteName('a.last_used_ip'),
            $db->quoteName('a.requests_count'),
            $db->quoteName('a.created'),
            $db->quoteName('u.name', 'user_name'),
            $db->quoteName('u.username', 'user_username'),
        ])
        ->from($db->quoteName('#__dharma_mcp_tokens', 'a'))
        ->leftJoin($db->quoteName('#__users', 'u'), $db->quoteName('u.id') . ' = ' . $db->quoteName('a.user_id'));

        $search = $this->getState('filter.search');
        if (!empty($search)) {
            if (stripos($search, 'id:') === 0) {
                $id = (int) substr($search, 3);
                $query->where($db->quoteName('a.id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);
            } else {
                $token = '%' . trim($search) . '%';
                $query->where('(' . $db->quoteName('a.title') . ' LIKE :search1 OR ' . $db->quoteName('u.name') . ' LIKE :search2)')
                    ->bind(':search1', $token)
                    ->bind(':search2', $token);
            }
        }

        $state = $this->getState('filter.state');
        if (is_numeric($state)) {
            $state = (int) $state;
            $query->where($db->quoteName('a.state') . ' = :state')->bind(':state', $state, ParameterType::INTEGER);
        }

        $orderCol = $this->state->get('list.ordering', 'a.id');
        $orderDirn = $this->state->get('list.direction', 'DESC');
        $query->order($db->escape($orderCol . ' ' . $orderDirn));

        return $query;
    }
}
