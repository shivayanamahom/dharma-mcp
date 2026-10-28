<?php
/** Dharma MCP — инструменты RadicalMart. */
declare(strict_types=1);
namespace Dharma\Plugin\DharmaMcp\Radicalmart\Tool;
\defined('_JEXEC') or die;
use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
use Dharma\Component\DharmaMcp\Site\Tool\AbstractTool;
use Dharma\Component\DharmaMcp\Site\Tool\ImageStore;
use Dharma\Plugin\DharmaMcp\Radicalmart\Entity\EntityCatalog;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;

abstract class AbstractRadicalmartTool extends AbstractTool
{
    public function getPack(): string { return 'radicalmart'; }

    protected function prepare(ToolContext $context): void
    {
        if (!$context->componentEnabled('com_radicalmart')) {
            throw ToolException::notFound('RadicalMart is not installed or is disabled.');
        }
        $version = (string) simplexml_load_file(JPATH_ADMINISTRATOR . '/components/com_radicalmart/radicalmart.xml')->version;
        if (version_compare($version, '3.0.27', '<')) {
            throw ToolException::validation('This pack requires RadicalMart 3.0.27 or later.');
        }
        $context->requireCan('core.manage', 'com_radicalmart');
        $context->app->getLanguage()->load('com_radicalmart', JPATH_ADMINISTRATOR);
        if ($context->app->getDocument() === null) {
            $context->app->loadDocument(Factory::getContainer()->get(\Joomla\CMS\Document\FactoryInterface::class)->createDocument('html'));
        }
        Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_radicalmart/forms');
        Form::addFieldPath(JPATH_ADMINISTRATOR . '/components/com_radicalmart/src/Field');
    }

    protected static function entityProperty(): array
    {
        return self::str('Entity kind. Call radicalmart.entities first; fieldset, meta and status are read-only.', ['enum' => array_keys(EntityCatalog::DEFINITIONS)]);
    }

    protected function definition(array $arguments): array
    {
        $key = self::requireString($arguments, 'entity');
        if (!isset(EntityCatalog::DEFINITIONS[$key])) {
            throw ToolException::validation('Unknown RadicalMart entity.');
        }
        return ['key' => $key] + EntityCatalog::DEFINITIONS[$key];
    }

    protected function model(ToolContext $context, array $def): object
    {
        $this->prepare($context);
        $context->requireCan('core.manage', 'com_radicalmart.' . $def['section']);
        return $context->model('com_radicalmart', $def['model']);
    }

    protected static function plain(mixed $value): mixed
    {
        if ($value instanceof Registry) { $value = $value->toArray(); }
        if (is_object($value)) { $value = get_object_vars($value); }
        if (is_array($value)) { return array_map([self::class, 'plain'], $value); }
        return $value;
    }

    protected function load(ToolContext $context, array $def, int $id): array
    {
        if ($id < 1) { throw ToolException::validation('id must be positive.'); }
        $item = $this->model($context, $def)->getItem($id);
        if (!$item || empty($item->id)) { throw ToolException::notFound('RadicalMart record not found.'); }
        return self::plain($item);
    }

    protected function authorize(ToolContext $context, array $def, string $action, array $row = []): void
    {
        $asset = 'com_radicalmart.' . $def['section'];
        // RadicalMart uses section assets (products, categories, fields); edit.own is checked against the actual record.
        if ($action === 'core.edit' && !$context->can($action, $asset) && !empty($row['id'])
            && (int) ($row['created_by'] ?? 0) === (int) $context->user()->id && $context->can('core.edit.own', $asset)) {
            return;
        }
        $context->requireCan($action, $asset);
    }

    protected function clean(ToolContext $context, array $def, array $row, bool $detail = true): array
    {
        $keys = $detail ? array_merge(['id','in_stock','created','modified'], $def['fields'])
            : ['id','title','alias','code','type','category','parent_id','state','prices','stock','in_stock','area','plugin'];
        $out = array_intersect_key($row, array_flip($keys));
        if (isset($out['category']) && is_array($out['category'])) { $out['category'] = (int) ($out['category']['id'] ?? 0); }
        foreach (['title','description','introtext','fulltext','search_text','note','metadesc','metakey'] as $key) {
            if (isset($out[$key]) && is_scalar($out[$key])) {
                $out[$key] = $context->userContent(mb_substr((string) $out[$key], 0, 12000));
            }
        }
        // Fields, plugin options and parameter texts also originate from site content, never from the server.
        foreach (['fields', 'params', 'options'] as $key) {
            if (isset($out[$key])) { $out[$key] = ['user_content' => $out[$key]]; }
        }
        return $out;
    }

    protected function form(ToolContext $context, array $def, array $data): array
    {
        $model = $this->model($context, $def);
        $model->setState(strtolower($def['model']) . '.id', (int) ($data['id'] ?? 0));
        $model->setState('save.task', 'save');
        $form = $model->getForm($data, false);
        if (!$form) { throw ToolException::validation('Cannot load the RadicalMart form.'); }
        // Native addPricesFields checks JPATH_COMPONENT before searching Form paths. During MCP this
        // constant points at com_dharma_mcp, so the native price subform is silently omitted.
        // Load the same RM XML and prepare-prices event without changing any RM files or persistence.
        if ($def['key'] === 'product') {
            $currencies = \Joomla\Component\RadicalMart\Administrator\Helper\PriceHelper::getCurrencies();
            $missing = false;
            foreach ($currencies as $currency) {
                $missing = $missing || !$form->getField('base', 'prices.' . $currency['group']);
            }
            if ($missing) {
                $prices = Form::getInstance('dharma_mcp.radicalmart.product_prices', JPATH_ADMINISTRATOR . '/components/com_radicalmart/forms/product_prices.xml', ['control' => 'jform_prices'], true);
                \Joomla\Component\RadicalMart\Administrator\Helper\PluginsHelper::triggerPlugins(['radicalmart', 'system'], 'onRadicalMartPreparePricesForm', [$prices, $data]);
                foreach ($currencies as $currency) {
                    $xml = $prices->getXml()->asXML();
                    foreach ($currency as $key => $value) {
                        if (is_scalar($value)) { $xml = str_replace('{currency_' . $key . '}', (string) $value, $xml); }
                    }
                    $form->load($xml, false);
                }
            }
        }
        // MCP accepts patches of editable data, not localized calendar input or author/rules fields.
        // Remove unrelated controls so validation does not reset their defaults on partial updates.
        foreach ($form->getGroup('') as $field) {
            $group = (string) $field->group;
            $top = $group !== '' ? explode('.', $group)[0] : (string) $field->fieldname;
            if ($top !== 'id' && !in_array($top, $def['fields'], true)) {
                $form->removeField($field->fieldname, $group ?: null);
            }
        }
        return [$model, $form];
    }

    protected function formSchema(ToolContext $context, array $def, array $data): array
    {
        [, $form] = $this->form($context, $def, $data);
        $fields = [];
        foreach ($form->getGroup('') as $field) {
            $group = (string) $field->group;
            $name = $group !== '' ? $group . '.' . $field->fieldname : (string) $field->fieldname;
            $top = explode('.', $name)[0];
            if (!in_array($top, $def['fields'], true)) { continue; }
            $entry = ['name' => $name, 'type' => $field->type, 'required' => (bool) $field->required,
                'readonly' => (bool) $field->readonly, 'label' => $context->userContent(Text::_((string) $field->getAttribute('label')))];
            $xml = $field->element;
            if ($xml && count($xml->option)) {
                $entry['options'] = [];
                foreach ($xml->option as $option) {
                    $entry['options'][] = ['value' => (string) $option['value'], 'label' => $context->userContent(Text::_((string) $option))];
                }
            }
            $fields[] = $entry;
        }
        return $fields;
    }

    protected static function dataArgument(array $arguments, bool $required = true): array
    {
        $data = $arguments['data'] ?? [];
        if (!is_array($data) || ($data !== [] && array_is_list($data)) || ($required && $data === [])) {
            throw ToolException::validation('data must be an object with named fields.');
        }
        return $data;
    }

    protected function assertFormPatch(Form $form, array $patch, string $group = ''): void
    {
        foreach ($patch as $name => $value) {
            $field = $form->getField((string) $name, $group ?: null);
            if ($field) {
                if ($field->readonly || $field->disabled) { throw ToolException::forbidden('Native form field is read-only: ' . $group . '.' . $name); }
                continue; // Native validation handles subforms and list entries.
            }
            $path = $group !== '' ? $group . '.' . $name : (string) $name;
            if (is_array($value) && $value !== []) {
                $this->assertFormPatch($form, $value, $path);
            } else {
                throw ToolException::validation('Unknown native form field or group: ' . $path);
            }
        }
    }

    protected function validatePatch(array $def, array $data): void
    {
        if (!$def['write']) { throw ToolException::validation('This entity is read-only in the RadicalMart pack.'); }
        if ($data === [] || array_is_list($data)) { throw ToolException::validation('data must be a non-empty object.'); }
        $unknown = array_diff(array_keys($data), $def['fields']);
        if ($unknown !== []) { throw ToolException::validation('Unsupported fields: ' . implode(', ', $unknown)); }
        if (isset($data['state']) && !in_array($data['state'], [0, 1, '0', '1'], true)) {
            throw ToolException::validation('state must be 0 or 1; use entity_trash to move a record to trash.');
        }
    }

    /**
     * Галерея товара или категории (media.gallery): каждая картинка — {"type": "image", "src": "images/...", "alt": "..."}.
     * Путь должен вести к файлу на сайте (например, из radicalmart.image_upload); тип по умолчанию — image.
     */
    protected static function normalizeGallery(array &$patch): void
    {
        if (!isset($patch['media']['gallery'])) { return; }
        $gallery = $patch['media']['gallery'];
        if (!is_array($gallery) || ($gallery !== [] && !array_is_list($gallery))) {
            throw ToolException::validation('media.gallery must be a list of {"type": "image", "src": "images/...", "alt": "..."}.');
        }
        foreach ($gallery as $i => $item) {
            if (!is_array($item) || !is_string($item['src'] ?? null)) {
                throw ToolException::validation('Each media.gallery item needs a "src" path, e.g. from radicalmart.image_upload.');
            }
            $type = (string) ($item['type'] ?? 'image');
            if ($type !== 'image') { throw ToolException::validation('Only image items are supported in media.gallery.'); }
            $src = ImageStore::existingPath($item['src']);
            if ($src === '') { throw ToolException::validation('A gallery item needs a non-empty "src".'); }
            $alt = $item['alt'] ?? '';
            if (!is_string($alt) || mb_strlen($alt) > 255) { throw ToolException::validation('The gallery "alt" must be a string of up to 255 characters.'); }
            $gallery[$i] = ['type' => 'image', 'src' => $src, 'alt' => $alt] + array_diff_key($item, ['type' => 1, 'src' => 1, 'alt' => 1]);
        }
        $patch['media']['gallery'] = $gallery;
    }

    /** Merge maps, replace numeric lists (gallery, options) rather than retaining removed list entries. */
    protected static function merge(array $source, array $patch): array
    {
        foreach ($patch as $key => $value) {
            $source[$key] = is_array($value) && !array_is_list($value) && isset($source[$key]) && is_array($source[$key])
                ? self::merge($source[$key], $value) : $value;
        }
        return $source;
    }

    protected function save(ToolContext $context, array $def, array $data): int
    {
        [$model, $form] = $this->form($context, $def, $data);
        try {
            $valid = $model->validate($form, $data);
        } catch (\Exception $e) {
            throw ToolException::validation(Text::_($e->getMessage()));
        }
        if ($valid === false) {
            $errors = array_map(static fn ($e) => Text::_($e instanceof \Throwable ? $e->getMessage() : (string) $e), $model->getErrors());
            throw ToolException::validation(implode('; ', array_slice($errors, 0, 5)) ?: 'Invalid RadicalMart form data.');
        }
        $valid['id'] = (int) ($data['id'] ?? 0);
        // Guard the draft state after validation as well as before it.
        if ($valid['id'] === 0) { $valid['state'] = 0; }
        $id = $model->save($valid);
        if ($id === false) { throw ToolException::validation(Text::_((string) $model->getError())); }
        return (int) $id;
    }

    protected function order(ToolContext $context, int $id): array
    {
        $this->prepare($context);
        $context->requireCan('core.manage', 'com_radicalmart.orders');
        if ($id < 1) { throw ToolException::validation('order_id must be positive.'); }
        $model = $context->model('com_radicalmart', 'Order');
        $row = $model->getFormData($id);
        if (!$row || empty($row['id'])) { throw ToolException::notFound('Order not found.'); }
        if ((int) $row['id'] !== $id) { throw ToolException::conflict('Native order edit state refers to a different order.'); }
        return [$model, self::plain($row)];
    }

    protected function cleanOrder(ToolContext $context, array $row, bool $detail): array
    {
        $out = array_intersect_key($row, array_flip(['id','number','status','currency','total','created','modified','state']));
        if (isset($out['status']) && is_array($out['status'])) {
            $out['status'] = ['id' => (int) ($out['status']['id'] ?? 0), 'title' => $context->userContent((string) ($out['status']['title'] ?? ''))];
        }
        if (is_array($out['currency'] ?? null)) { $out['currency'] = $out['currency']['code'] ?? ''; }
        if (is_array($out['total'] ?? null)) {
            $out['total'] = array_intersect_key($out['total'], array_flip(['fee','base','final','discount','products','quantity','order_discount']));
        }
        if ($detail) {
            $out['products'] = [];
            foreach (($row['products'] ?? []) as $product) {
                $p = array_intersect_key((array) $product, array_flip(['id','title','code','quantity','price','base','final','discount','sum_base','sum_final','total']));
                if (!isset($p['title']) && !empty($p['id'])) {
                    $productModel = $context->model('com_radicalmart', 'Product');
                    $product = $productModel->getItem((int) $p['id']);
                    if ($product && !empty($product->id)) { $p['title'] = (string) $product->title; $p['code'] = (string) $product->code; }
                }
                if (isset($p['title'])) { $p['title'] = $context->userContent((string) $p['title']); }
                $out['products'][] = $p;
            }
            // Arbitrary contact fields and notes can contain PII under any key; raw values are opt-in only.
            $out['customer_contacts'] = $context->piiAllowed() && !$context->maskPii()
                ? $context->userContent(json_encode($row['contacts'] ?? [], JSON_UNESCAPED_UNICODE)) : '[REDACTED]';
            $out['customer_note'] = $context->piiAllowed() && !$context->maskPii()
                ? $context->userContent((string) ($row['note'] ?? '')) : '[REDACTED]';
        }
        return $out;
    }
}
