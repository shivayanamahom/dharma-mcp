<?php

/**
 * Dharma MCP — набор «Система».
 *
 * @package    plg_dharmamcp_system
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\System\Tool;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;

final class DbTablesTool extends AbstractSystemTool
{
    public function getName(): string
    {
        return 'db.tables';
    }

    public function getTitle(): string
    {
        return 'Database tables';
    }

    public function getDescription(): string
    {
        return 'Lists database tables of the site (the table prefix is shown as #__) with approximate row count and size. '
            . 'With "table" returns the columns of that table. Use it before db.query.';
    }

    public function getRequiredScope(): string
    {
        return 'system:read';
    }

    public function getAccess(): Access
    {
        return Access::Read;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'search' => self::str('Only tables whose name contains this text.'),
            'table' => self::str('Show the columns of this table, e.g. "#__content".'),
        ] + self::paging(200));
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $this->admin($context);
        $db = $context->db;
        $prefix = $db->getPrefix();

        $table = self::optionalString($arguments, 'table');
        if ($table !== null && $table !== '') {
            $real = str_starts_with($table, '#__') ? $prefix . substr($table, 3) : $table;
            if (!preg_match('/^[A-Za-z0-9_]+$/', $real) || !str_starts_with($real, $prefix)) {
                throw ToolException::validation('Use a table name of this site, for example "#__content".');
            }
            $columns = [];
            foreach ($db->setQuery('SHOW COLUMNS FROM ' . $db->quoteName($real))->loadAssocList() as $c) {
                $columns[] = ['name' => $c['Field'], 'type' => $c['Type'], 'null' => $c['Null'] === 'YES', 'key' => $c['Key'] ?: null, 'default' => $c['Default']];
            }

            return ['table' => '#__' . substr($real, \strlen($prefix)), 'columns' => $columns];
        }

        $search = strtolower((string) (self::optionalString($arguments, 'search') ?? ''));
        [$limit, $offset] = $context->page($arguments, 100, 200);
        $rows = [];
        foreach ($db->setQuery('SHOW TABLE STATUS')->loadAssocList() as $t) {
            $name = (string) $t['Name'];
            if (!str_starts_with($name, $prefix) || ($search !== '' && !str_contains(strtolower($name), $search))) {
                continue;
            }
            $rows[] = [
                'table' => '#__' . substr($name, \strlen($prefix)),
                'engine' => $t['Engine'],
                'rows_approx' => (int) $t['Rows'],
                'size_mb' => round(((int) $t['Data_length'] + (int) $t['Index_length']) / 1048576, 2),
            ];
        }
        $total = \count($rows);

        return ['total' => $total, 'limit' => $limit, 'offset' => $offset, 'has_more' => $offset + $limit < $total, 'tables' => \array_slice($rows, $offset, $limit)];
    }
}
