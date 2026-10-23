<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Bootstrap Blocks',
    'description' => 'Content Blocks v0.7 for TYPO3 v12: CTA & Teaser using Core fields and Link Browser attributes.',
    'category' => 'fe',
    'state' => 'beta',
    'author' => 'Belsignum',
    'version' => '0.2.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'content_blocks' => ''
        ],
        'suggests' => [
            'imagelazyload' => ''
        ],
    ],
];
