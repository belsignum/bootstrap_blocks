<?php

if (!defined('TYPO3'))
{
    die('Access denied.');
}

use TYPO3\CMS\Backend\Form\FormDataProvider\TcaColumnsProcessCommon;
use TYPO3\CMS\Backend\Form\FormDataProvider\TcaSelectItems;
use Belsignum\BootstrapBlocks\Form\FormDataProvider\BootstrapBlocksItemGroupsProvider;

// Sichere Registrierung in der TCA-FormData-Chain:
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['formDataGroup']['tcaDatabaseRecord'][BootstrapBlocksItemGroupsProvider::class] = [
    'before' => [TcaSelectItems::class],
    // wir müssen VOR SelectItems rein
    'after'  => [TcaColumnsProcessCommon::class]
    // aber NACH ColumnsProcessCommon
];
