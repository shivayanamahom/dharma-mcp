<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaShopMcp\Site\Tool;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaShopMcp\Site\Auth\AuthResult;

interface ToolInterface
{
    public function getName(): string;
    public function getDescription(): string;
    public function getRequiredScope(): string;
    public function getInputSchema(): array;
    public function isReadOnly(): bool;
    public function isDestructive(): bool;
    public function execute(array $arguments, AuthResult $auth): array;
}
