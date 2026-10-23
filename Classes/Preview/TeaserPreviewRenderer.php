<?php
declare(strict_types=1);

namespace Belsignum\BootstrapBlocks\Preview;

use TYPO3\CMS\Backend\View\BackendLayout\Grid\GridColumnItem;
use TYPO3\CMS\ContentBlocks\Backend\Preview\PreviewRenderer;

/**
 * Page module preview of the teaser. The core preview header shows header and subheader, but the teaser uses the
 * subheader as button label, which its EditorPreview.html names already; so the header leaves it out.
 *
 * Extends the Content Blocks renderer to keep EditorPreview.html for the content. That class is marked @internal
 * (Content Blocks 0.7), so check this one on updates.
 */
final class TeaserPreviewRenderer extends PreviewRenderer
{
    public function renderPageModulePreviewHeader(GridColumnItem $item): string
    {
        $headerItem = clone $item;
        $headerItem->setRecord(array_replace($item->getRecord(), ['subheader' => '']));
        return parent::renderPageModulePreviewHeader($headerItem);
    }
}
