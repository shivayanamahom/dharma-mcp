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

use Dharma\Component\DharmaMcp\Site\Tool\AbstractTool;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
use Joomla\CMS\Form\Form;

/**
 * Общее для инструментов клиники: компонент com_dharmaclinic, его язык и формы.
 *
 * Принцип тот же, что у магазина: чтение — через модели компонента, запись — только через его формы, модели и службы
 * (проверка, события, связи, пересчёты как в админке). Права — действия Joomla на компоненте com_dharmaclinic
 * (`core.edit`, `lead.edit`, `review.moderate`…) от имени пользователя токена.
 */
abstract class AbstractClinicTool extends AbstractTool
{
    public const COMPONENT = 'com_dharmaclinic';

    /**
     * Данные клиники (филиал, врачи, услуги, цены) публичны и не маскируются сервером; персональные данные пациентов
     * (заявки, контакты авторов отзывов) отдают только инструменты leads.* и reviews.*, которые переопределяют этот метод.
     */
    public function masksPersonalData(array $arguments): bool
    {
        return false;
    }

    public function getPack(): string
    {
        return 'clinic';
    }

    /** Компонент установлен и включён, язык и пути форм подготовлены, документ страницы создан. */
    protected function boot(ToolContext $context): void
    {
        if (!$context->componentEnabled(self::COMPONENT)) {
            throw ToolException::notFound('Dharma Clinic (com_dharmaclinic) is not installed or is disabled on this site.');
        }

        // Поля форм компонента при создании обращаются к документу страницы; в запросе к MCP его нет, поэтому создаётся пустой.
        if ($context->app->getDocument() === null) {
            $context->app->loadDocument(\Joomla\CMS\Factory::getContainer()->get(\Joomla\CMS\Document\FactoryInterface::class)->createDocument('html'));
        }

        $context->app->getLanguage()->load(self::COMPONENT, JPATH_ADMINISTRATOR);

        // Формы моделей ищутся по константе JPATH_COMPONENT, а в запросе к MCP это com_dharma_mcp: пути задаются явно.
        Form::addFormPath(JPATH_ADMINISTRATOR . '/components/' . self::COMPONENT . '/forms');
        Form::addFieldPath(JPATH_ADMINISTRATOR . '/components/' . self::COMPONENT . '/src/Field');
    }

    /** Административная модель компонента (`Doctor`, `Service`…). */
    protected function adminModel(ToolContext $context, string $name): object
    {
        $this->boot($context);

        return $context->model(self::COMPONENT, $name);
    }

    /** Ответ для списков: единый вид пагинации. */
    protected static function paged(array $items, int $total, int $limit, int $offset): array
    {
        return [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $offset + \count($items) < $total,
            'items' => $items,
        ];
    }
}
