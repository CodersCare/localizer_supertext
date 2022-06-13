<?php
if (!defined('TYPO3_MODE')) {
    die ('Access denied.');
}

$GLOBALS['TCA']['tx_localizer_settings']['columns']['type']['config']['items'][] = [
    'LLL:EXT:localizer_supertext/Resources/Private/Language/locallang_db.xlf:tx_localizer_settings.type.I.localizer_supertext', 'localizer_supertext'
];


$GLOBALS['TCA']['tx_localizer_settings']['types']['localizer_supertext']['showitem'] = 'hidden, --palette--;;1, type, title, description, url, username, password;LLL:EXT:localizer_supertext/Resources/Private/Language/locallang_db.xlf:tx_localizer_settings.password, --palette--;;2, --palette--;;3, l10n_cfg, source_locale, target_locale';
