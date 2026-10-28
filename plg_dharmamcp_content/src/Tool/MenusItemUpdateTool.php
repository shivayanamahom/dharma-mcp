<?php

/**
 * Dharma MCP — набор «Материалы и меню».
 *
 * @package    plg_dharmamcp_content
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Content\Tool;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Table\Menu as MenuTable;
use Joomla\Registry\Registry;

/**
 * Мета-данные пункта меню сайта: описание, ключевые слова и заголовок окна. Пишутся только эти три параметра пункта,
 * остальное (тип, ссылка, алиас, положение в дереве, остальные параметры) не меняется, поэтому используется таблица Joomla,
 * а не полная форма пункта.
 */
final class MenusItemUpdateTool extends AbstractContentTool
{
    /** Поле инструмента => ключ параметра пункта меню. */
    private const FIELDS = [
        'meta_description' => 'menu-meta_description',
        'meta_keywords' => 'menu-meta_keywords',
        'page_title' => 'page_title',
    ];

    private const MAX = 500;

    public function getName(): string
    {
        return 'menus.item_update';
    }

    public function getTitle(): string
    {
        return 'Set menu item meta data';
    }

    public function getDescription(): string
    {
        return 'Sets the SEO meta data of one site menu item: meta description (about 150 characters), meta keywords and the browser page title. '
            . 'An empty string clears a value, an omitted field stays unchanged. The item type, link, alias and position are not touched. '
            . 'The page changes for visitors at once, so the user must confirm. Find ids with menus.items.';
    }

    public function getRequiredScope(): string
    {
        return 'content:write';
    }

    public function getAccess(): Access
    {
        return Access::Write;
    }

    public function isIdempotent(): bool
    {
        return true;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'id' => self::int('Menu item id (see menus.items).'),
            'meta_description' => self::str('Meta description of the page (up to ' . self::MAX . ' characters; about 150 reads best).'),
            'meta_keywords' => self::str('Meta keywords, comma separated.'),
            'page_title' => self::str('Browser page title (the <title> of the page).'),
        ], ['id']);
    }

    /** @return array<string, string>  Параметры пункта: ключ => новое значение. */
    private function changes(array $arguments): array
    {
        $changes = [];
        foreach (self::FIELDS as $field => $key) {
            if (!\array_key_exists($field, $arguments)) {
                continue;
            }
            $value = $arguments[$field];
            if (!\is_string($value)) {
                throw ToolException::validation("Parameter '$field' must be a string.");
            }
            $value = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');
            if (mb_strlen($value) > self::MAX) {
                throw ToolException::validation("Parameter '$field' must be up to " . self::MAX . ' characters.');
            }
            $changes[$key] = $value;
        }

        if ($changes === []) {
            throw ToolException::validation('Pass at least one field to change.', ['fields' => array_keys(self::FIELDS)]);
        }

        return $changes;
    }

    private function load(ToolContext $context, int $id): MenuTable
    {
        $this->lang($context, 'com_menus');
        $table = new MenuTable($context->db);
        // id 1 — служебный корень дерева меню, у него нет типа меню; в «меню» он не входит.
        if ($id < 2 || !$table->load($id) || (int) $table->client_id !== 0 || (string) $table->menutype === '') {
            throw ToolException::notFound("Site menu item $id not found.");
        }
        if ((int) $table->published === -2) {
            throw ToolException::validation('The menu item is in the trash.');
        }

        $db = $context->db;
        $query = $db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__menu_types'))
            ->where($db->quoteName('menutype') . ' = :menutype')->bind(':menutype', $table->menutype);
        $context->requireCan('core.edit', 'com_menus.menu.' . (int) $db->setQuery($query)->loadResult(), 'edit this menu item');

        return $table;
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        $table = $this->load($context, self::requireInt($arguments, 'id'));
        $changes = $this->changes($arguments);
        $params = new Registry((string) $table->params);

        $diff = [];
        foreach (self::FIELDS as $field => $key) {
            if (isset($changes[$key])) {
                $diff[$field] = ['from' => (string) $params->get($key, ''), 'to' => $changes[$key]];
            }
        }

        return [
            'action' => 'Set menu item meta data',
            'item' => ['id' => (int) $table->id, 'title' => (string) $table->title, 'menu' => (string) $table->menutype],
            'live_now' => (int) $table->published === 1,
            'changes' => $diff,
        ];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $table = $this->load($context, self::requireInt($arguments, 'id'));
        $changes = $this->changes($arguments);

        $params = new Registry((string) $table->params);
        foreach ($changes as $key => $value) {
            $params->set($key, $value);
        }
        $table->params = $params->toString();

        if (!$table->check() || !$table->store()) {
            throw ToolException::validation('Joomla could not save the menu item: ' . strip_tags((string) $table->getError()));
        }

        // Меню и модули кэшируются: без очистки посетители видят прежние теги до истечения срока.
        $cache = Factory::getContainer()->get(CacheControllerFactoryInterface::class);
        foreach (['_system', 'com_modules', 'com_menus'] as $group) {
            $cache->createCacheController('callback', ['defaultgroup' => $group])->clean();
        }

        $fields = [];
        foreach (self::FIELDS as $field => $key) {
            if (isset($changes[$key])) {
                $fields[] = $field;
            }
        }

        return ['updated' => true, 'id' => (int) $table->id, 'title' => (string) $table->title, 'changed_fields' => $fields];
    }
}
