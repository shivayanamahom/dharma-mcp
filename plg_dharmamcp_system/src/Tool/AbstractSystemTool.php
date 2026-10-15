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

use Dharma\Component\DharmaMcp\Site\Tool\AbstractTool;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;

/**
 * Основа системных инструментов. Все они доступны только Суперпользователю Joomla и только токенам
 * с областью system:*; набор по умолчанию выключен в менеджере плагинов.
 */
abstract class AbstractSystemTool extends AbstractTool
{
    /** Параметры конфигурации, которые можно ЧИТАТЬ (секреты — пароли, ключи, доступы к БД и почте — исключены). */
    protected const READABLE_CONFIG = [
        'sitename', 'offline', 'offline_message', 'display_offline_message', 'access', 'list_limit', 'caching', 'cachetime',
        'cache_handler', 'gzip', 'sef', 'sef_rewrite', 'sef_suffix', 'unicodeslugs', 'lifetime', 'session_handler', 'debug',
        'error_reporting', 'force_ssl', 'MetaDesc', 'MetaKeys', 'MetaAuthor', 'MetaVersion', 'robots', 'offset', 'mailonline',
        'mailer', 'mailfrom', 'fromname', 'tmp_path', 'log_path', 'live_site', 'helpurl',
    ];

    /** Параметры, которые можно ИЗМЕНЯТЬ (подмножество читаемых). Значение — допустимые варианты или тип. */
    protected const WRITABLE_CONFIG = [
        'sitename' => 'string',
        'offline' => ['0', '1'],
        'offline_message' => 'string',
        'display_offline_message' => ['0', '1', '2'],
        'MetaDesc' => 'string',
        'MetaKeys' => 'string',
        'MetaAuthor' => ['0', '1'],
        'list_limit' => 'int',
        'caching' => ['0', '1', '2'],
        'cachetime' => 'int',
        'lifetime' => 'int',
    ];

    public function getPack(): string
    {
        return 'system';
    }

    /** Системные инструменты — только для Суперпользователя. */
    protected function admin(ToolContext $context): void
    {
        $context->requireSuperUser();
    }
}
