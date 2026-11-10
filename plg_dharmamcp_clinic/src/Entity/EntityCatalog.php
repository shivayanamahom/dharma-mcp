<?php

/**
 * Dharma MCP — набор «Клиника».
 *
 * @package    plg_dharmamcp_clinic
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Clinic\Entity;

\defined('_JEXEC') or die;

/**
 * Справочник сущностей клиники, которыми можно управлять общими инструментами clinic.entity_*.
 *
 * Запись всегда идёт через формы и модели компонента com_dharmaclinic (проверка, события, связи, коды, пересчёты), как в админке.
 * Здесь описано только то, чего модель о себе не сообщает: из какой таблицы показывать список, какой столбец хранит состояние
 * и можно ли сущность удалять.
 *
 * Ключи записи: label, summary — для ассистента; model — модель Administrator; table, columns, order, where — как строить список
 * (только чтение); state — столбец состояния (1 — опубликовано, 0 — снято, -2 — корзина); delete — 'trash' или 'none'.
 */
final class EntityCatalog
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $base = ['component' => 'com_dharmaclinic', 'state' => 'state', 'delete' => 'trash', 'hidden' => false, 'pii' => false, 'order' => 'ordering ASC, id ASC'];

        return [
            'direction' => [
                'label' => 'Direction',
                'summary' => 'A medical direction or a group of directions (a tree: a group has child directions). Its page lists services, doctors, prices and reviews. Fields: title, code, parent, subtitle, description, age group, photos, SEO, page blocks, "directions"-style links to services and doctors are set from the service and doctor side.',
                'model' => 'Direction', 'table' => '#__dharmaclinic_directions', 'order' => 'lft ASC', 'where' => 'level > 0',
                'columns' => ['id', 'parent_id', 'level', 'code', 'title', 'alias', 'age_group', 'state'],
            ] + $base,
            'service' => [
                'label' => 'Service',
                'summary' => 'A service page (appointment, procedure, test, therapy): texts, FAQ, gallery, indications, doctors, branches, directions and the primary direction. Prices are not entered here: they come from price items linked to the service.',
                'model' => 'Service', 'table' => '#__dharmaclinic_services',
                'columns' => ['id', 'code', 'title', 'alias', 'primary_direction_id', 'schema_type', 'age_group', 'state'],
            ] + $base,
            'doctor' => [
                'label' => 'Doctor',
                'summary' => 'A doctor profile: name, position, degree, practice start year, short intro, photo, qualifications, links to services, directions, diseases and branches.',
                'model' => 'Doctor', 'table' => '#__dharmaclinic_doctors', 'order' => 'ordering ASC, title ASC',
                'columns' => ['id', 'code', 'title', 'alias', 'position', 'age_group', 'state'],
            ] + $base,
            'disease' => [
                'label' => 'Disease',
                'summary' => 'A disease reference page: ICD code, short intro, description, symptoms, causes, diagnostics, treatment, when to see a doctor, related services and diseases. Medical texts need review by the clinic doctors.',
                'model' => 'Disease', 'table' => '#__dharmaclinic_diseases', 'order' => 'title ASC',
                'columns' => ['id', 'code', 'title', 'alias', 'icd_code', 'primary_direction_id', 'state'],
            ] + $base,
            'branch' => [
                'label' => 'Branch',
                'summary' => 'A clinic branch (address, phones, hours, map coordinates). The main branch is used across the site (header, footer, markup). Do not delete the main branch.',
                'model' => 'Branch', 'table' => '#__dharmaclinic_branches',
                'columns' => ['id', 'code', 'title', 'alias', 'state'],
            ] + $base,
            'price_group' => [
                'label' => 'Price group',
                'summary' => 'A section of the price list ("Appointments", "Manipulations"). The first group by order is the main one for the "from" price of appointment services.',
                'model' => 'Pricegroup', 'table' => '#__dharmaclinic_price_groups',
                'columns' => ['id', 'code', 'title', 'state', 'ordering'],
            ] + $base,
            'price_item' => [
                'label' => 'Price item',
                'summary' => 'One position of the price list: title, variant, price, discount, group, links to services, directions and doctors. For many price changes use clinic.prices_batch. The price list is normally loaded from Excel by the clinic staff.',
                'model' => 'Priceitem', 'table' => '#__dharmaclinic_price_items', 'order' => 'id ASC',
                'columns' => ['id', 'code', 'title', 'variant', 'group_id', 'price', 'state'],
            ] + $base,
            'question' => [
                'label' => 'Question and answer',
                'summary' => 'A question with an answer for the "Questions and answers" page. Medical answers must be checked by a doctor before publishing.',
                'model' => 'Question', 'table' => '#__dharmaclinic_questions',
                'columns' => ['id', 'code', 'title', 'state', 'ordering'],
            ] + $base,
            'site_form' => [
                'label' => 'Site form',
                'summary' => 'A form of the site (callback, appointment, question, review, certificate order): fields, texts, anti-spam options. Forms cannot be deleted, only unpublished or edited.',
                'model' => 'Siteform', 'table' => '#__dharmaclinic_forms', 'delete' => 'none',
                'columns' => ['id', 'code', 'title', 'kind', 'state'],
            ] + $base,
            'lead_status' => [
                'label' => 'Lead status',
                'summary' => 'A status of leads ("New", "In progress", "Booked", "Spam"). Statuses cannot be deleted, only unpublished.',
                'model' => 'Leadstatus', 'table' => '#__dharmaclinic_lead_statuses', 'delete' => 'none',
                'columns' => ['id', 'code', 'title', 'state', 'ordering'],
            ] + $base,
            // Не входит в общие инструменты: отзывы ведут инструменты reviews.* (модерация, ответ).
            'review' => [
                'label' => 'Review', 'summary' => '', 'model' => 'Review', 'table' => '#__dharmaclinic_reviews', 'state' => 'status', 'hidden' => true,
                'columns' => ['id', 'author_name', 'status'],
            ] + $base,
        ];
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(array_filter(self::all(), static fn (array $e): bool => !$e['hidden']));
    }

    /** @return array<string, mixed> */
    public static function get(string $key): array
    {
        $all = self::all();

        if (!isset($all[$key])) {
            throw new \InvalidArgumentException("Unknown entity '$key'. Known: " . implode(', ', self::keys()) . '.');
        }

        return ['key' => $key] + $all[$key];
    }
}
