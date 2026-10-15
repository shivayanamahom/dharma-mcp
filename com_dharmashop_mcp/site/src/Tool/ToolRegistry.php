<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaShopMcp\Site\Tool;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaShopMcp\Site\Auth\AuthResult;

final class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    public function __construct()
    {
        $this->register(new PingTool());
        $this->register(new ShopInfoTool());
    }

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return array<int, array>
     */
    public function getToolsListForAuth(AuthResult $auth, string $serverMode): array
    {
        $list = [];
        foreach ($this->tools as $tool) {
            // Проверка скоупа
            if (!$auth->hasScope($tool->getRequiredScope())) {
                continue;
            }

            // Проверка режима сервера (если только чтение, не отдаем методы записи)
            if ($serverMode === 'read_only' && !$tool->isReadOnly()) {
                continue;
            }

            $list[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema(),
                'annotations' => [
                    'readOnlyHint' => $tool->isReadOnly(),
                    'destructiveHint' => $tool->isDestructive(),
                ],
            ];
        }
        return $list;
    }
}
