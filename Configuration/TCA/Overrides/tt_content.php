<?php

if (!defined('TYPO3'))
{
    die('Access denied.');
}

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

// Optional: lazy loading per teaser image with t3brightside/imagelazyload ("Use in custom elements" in its README).
// The extension is suggested in composer.json and ext_emconf.php, so its TCA is loaded before this file; the teaser
// type exists, because Content Blocks (required) adds its TCA in its own override file in TYPO3 v12.
if (isset($GLOBALS['TCA']['tt_content']['columns']['tx_imagelazyload']))
{
    ExtensionManagementUtility::addToAllTCAtypes(
        'tt_content',
        '--palette--;;imagelazyload',
        'belsignum_bootstrapblocksteaser',
        'after:image_zoom'
    );
}
