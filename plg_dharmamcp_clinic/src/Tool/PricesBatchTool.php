<?php

/**
 * Dharma MCP — набор «Клиника».
 *
 * @package    plg_dharmamcp_clinic
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Clinic\Tool;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
use Dharma\Plugin\DharmaMcp\Clinic\Entity\EntityCatalog;

/** Массовое изменение цен позиций прайса: по списку или на процент в группе. Каждая позиция сохраняется через форму и модель. */
final class PricesBatchTool extends AbstractEntityTool
{
    private const MAX_ITEMS = 100;

    public function getName(): string
    {
        return 'clinic.prices_batch';
    }

    public function getTitle(): string
    {
        return 'Change prices of several price list items';
    }

    public function getDescription(): string
    {
        return 'Changes prices of up to 100 positions of the price list at once. Either pass "items": [{"id": 12, "price": 2500}] (optionally "discount" as an amount or "discount_percent"), '
            . 'or "group_id" with "percent" to raise or lower every published position of a price group (for example 5 for +5%) with rounding to whole rubles. Before changing, the user sees how many positions change and the first 30 old → new prices. '
            . 'Prices are shown to patients immediately and the clinic normally loads the whole price list from Excel: use this only for small, explicit corrections the user asked for.';
    }

    public function getRequiredScope(): string
    {
        return 'clinic:write';
    }

    public function getAccess(): Access
    {
        return Access::Write;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'items' => ['type' => 'array', 'maxItems' => self::MAX_ITEMS, 'description' => 'List of {"id": int, "price": number, "discount": number?, "discount_percent": number?}.', 'items' => ['type' => 'object']],
            'group_id' => self::int('Price group id (use with "percent").'),
            'percent' => ['type' => 'number', 'description' => 'Percent change for the whole group, from -50 to 100 (for example 5 or -3.5).'],
        ]);
    }

    /** @return array<int, array{id: int, title: string, from: float, to: float, patch: array<string, mixed>}> */
    private function plan(array $arguments, ToolContext $context): array
    {
        $def = EntityCatalog::get('price_item');
        $this->prepare($context, $def);
        $this->authorize($context, $def, 'core.edit');
        $db = $context->db;
        $plan = [];

        if (!empty($arguments['items'])) {
            if (!\is_array($arguments['items']) || !array_is_list($arguments['items']) || \count($arguments['items']) > self::MAX_ITEMS) {
                throw ToolException::validation('"items" must be a list of up to ' . self::MAX_ITEMS . ' objects.');
            }

            foreach ($arguments['items'] as $row) {
                $id = (int) ($row['id'] ?? 0);
                $price = $row['price'] ?? null;

                if ($id < 1 || (!\is_int($price) && !\is_float($price)) || $price < 0 || $price > 10000000) {
                    throw ToolException::validation('Each item needs "id" and a numeric "price" from 0 to 10 000 000.');
                }

                $patch = ['price' => round((float) $price, 2)];

                foreach (['discount', 'discount_percent'] as $key) {
                    if (isset($row[$key])) {
                        if (!is_numeric($row[$key]) || (float) $row[$key] < 0) {
                            throw ToolException::validation("'$key' must be a non-negative number.");
                        }
                        $patch[$key] = round((float) $row[$key], 2);
                    }
                }

                $plan[$id] = ['patch' => $patch];
            }
        } elseif (isset($arguments['group_id'], $arguments['percent'])) {
            $percent = (float) $arguments['percent'];

            if ($percent < -50 || $percent > 100 || $percent == 0.0) {
                throw ToolException::validation('"percent" must be from -50 to 100 and not zero.');
            }

            $groupId = (int) $arguments['group_id'];
            $rows = $db->setQuery('SELECT id, price FROM ' . $db->quoteName('#__dharmaclinic_price_items') . ' WHERE state = 1 AND price IS NOT NULL AND group_id = ' . $groupId . ' ORDER BY id', 0, self::MAX_ITEMS + 1)->loadObjectList() ?: [];

            if (\count($rows) > self::MAX_ITEMS) {
                throw ToolException::validation('The group has more than ' . self::MAX_ITEMS . ' positions: change it in parts or load the price list from Excel in the admin.');
            }

            foreach ($rows as $row) {
                $plan[(int) $row->id] = ['patch' => ['price' => (float) round((float) $row->price * (1 + $percent / 100))]];
            }
        } else {
            throw ToolException::validation('Pass either "items", or "group_id" together with "percent".');
        }

        if ($plan === []) {
            throw ToolException::validation('There is nothing to change.');
        }

        $out = [];

        foreach ($plan as $id => $entry) {
            $current = $this->load($context, $def, (int) $id);
            $out[(int) $id] = ['id' => (int) $id, 'title' => trim($current['title'] . (($current['variant'] ?? '') !== '' ? ' (' . $current['variant'] . ')' : '')), 'from' => (float) ($current['price'] ?? 0), 'to' => (float) $entry['patch']['price'], 'patch' => $entry['patch']];
        }

        return $out;
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        $plan = $this->plan($arguments, $context);
        $changed = array_filter($plan, static fn (array $p): bool => $p['from'] !== $p['to'] || \count($p['patch']) > 1);

        return [
            'action' => 'Change prices',
            'positions' => \count($plan),
            'will_change' => \count($changed),
            'first_30' => array_values(array_map(static fn (array $p): array => ['id' => $p['id'], 'title' => $p['title'], 'from' => $p['from'], 'to' => $p['to']], \array_slice($changed, 0, 30, true))),
        ];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $plan = $this->plan($arguments, $context);
        $def = EntityCatalog::get('price_item');
        $model = $this->model($context, $def);
        $updated = 0;
        $skipped = 0;

        foreach ($plan as $id => $entry) {
            $item = $model->getItem($id);
            $full = json_decode((string) json_encode($item), true) ?: [];
            $merged = self::merge($full, $entry['patch']);
            $merged['id'] = $id;

            if (self::changes($full, $entry['patch']) === []) {
                $skipped++;

                continue;
            }

            $this->store($context, $def, $merged);
            $updated++;
        }

        return ['updated' => $updated, 'unchanged' => $skipped];
    }
}
