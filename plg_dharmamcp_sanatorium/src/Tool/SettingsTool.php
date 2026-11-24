<?php
declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Sanatorium\Tool;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;

\defined('_JEXEC') or die;

final class SettingsTool extends AbstractSettingsTool
{
    public function getName(): string
    {
        return 'sanatorium.settings';
    }

    public function getDescription(): string
    {
        return 'Read sanatorium component settings and discover editable fields, including SEO, booking links, reviews, image handling and notifications.';
    }

    public function getRequiredScope(): string
    {
        return 'sanatorium:read';
    }

    public function getAccess(): Access
    {
        return Access::Read;
    }

    public function getInputSchema(): array
    {
        return self::schema();
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        return $this->settings($arguments, $context, false, false);
    }
}
