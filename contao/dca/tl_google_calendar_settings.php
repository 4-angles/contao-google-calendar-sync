<?php

// contao/dca/tl_google_calendar_settings.php
// Config-based settings for Google Calendar (stored in system/config/localconfig.php)

$GLOBALS['TL_DCA']['tl_google_calendar_settings'] = [
    'config' => [
        'dataContainer' => \Contao\DC_File::class,
        'closed' => true,
        'notEditable' => false,
    ],
    'palettes' => [
        'default' => '{settings_legend},googleCalendarClientId,googleCalendarClientSecret,googleCalendarApplicationName'
    ],
    'fields' => [
        'googleCalendarClientId' => [
            'label' => ['Google Client ID', 'OAuth 2.0 Client ID from Google Cloud Console (overrides GOOGLE_CALENDAR_CLIENT_ID in .env if set)'],
            'inputType' => 'text',
            'eval' => ['maxlength' => 255, 'tl_class' => 'w50']
        ],
        'googleCalendarClientSecret' => [
            'label' => ['Google Client Secret', 'OAuth 2.0 Client Secret from Google Cloud Console (overrides GOOGLE_CALENDAR_CLIENT_SECRET in .env if set)'],
            'inputType' => 'text',
            'eval' => ['maxlength' => 255, 'tl_class' => 'w50', 'hideInput' => true]
        ],
        'googleCalendarApplicationName' => [
            'label' => ['Application Name', 'Name shown to users during OAuth (optional, overrides GOOGLE_CALENDAR_APPLICATION_NAME in .env if set)'],
            'inputType' => 'text',
            'eval' => ['maxlength' => 255, 'tl_class' => 'w50']
        ]
    ]
];
