<?php
declare(strict_types=1);

namespace Belsignum\BootstrapBlocks\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

final class CleanlineViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('content', 'string', 'Content to normalize into one clean line', false, null);
    }

    public function render(): string
    {
        $content = $this->arguments['content'] ?? (string)$this->renderChildren();

        // Ersetze Zeilenumbrüche und Tabs durch Spaces
        $content = str_replace(["\r", "\n", "\t"], ' ', $content);
        // Mehrfache Spaces zusammenfassen
        $content = preg_replace('/\s+/', ' ', $content) ?? $content;

        return trim($content);
    }
}
