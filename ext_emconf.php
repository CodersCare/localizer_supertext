<?php

/**
 * Extension Manager/Repository config file for ext "localizer_supertext".
 */
$EM_CONF['localizer_supertext'] = [
    'title' => 'Localizer Supertext',
    'description' => 'Supertext API for the TYPO3 localizer',
    'category' => 'templates',
    'constraints' => [
        'depends' => [
            'typo3' => '9.5.0-9.5.99',
            'localizer' => '9.0.0-9.99.99'
        ],
        'conflicts' => [
        ],
    ],
    'autoload' => [
        'psr-4' => [
            'Localizationteam\\LocalizerSupertext\\' => 'Classes'
        ],
    ],
    'state' => 'stable',
    'uploadfolder' => 0,
    'createDirs' => '',
    'clearCacheOnLoad' => 1,
    'author' => 'Jo Hasenau',
    'author_email' => 'info@cybercraft.de',
    'author_company' => 'Cybercraft GmbH',
    'version' => '9.0.0',
];
