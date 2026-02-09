<?php

// contao/dca/tl_calendar.php
// Extend tl_calendar with Google Calendar sync settings

use Contao\CoreBundle\DataContainer\PaletteManipulator;
use Contao\Backend;
use Contao\System;

// Add fields to database
$GLOBALS['TL_DCA']['tl_calendar']['fields']['google_sync_enabled'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_calendar']['google_sync_enabled'],
    'inputType' => 'checkbox',
    'eval' => ['submitOnChange' => true, 'tl_class' => 'w50 m12'],
    'sql' => "char(1) NOT NULL default ''",
];

$GLOBALS['TL_DCA']['tl_calendar']['fields']['google_calendar_id_import'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_calendar']['google_calendar_id_import'],
    'inputType' => 'select',
    'options_callback' => ['tl_calendar_google', 'getGoogleCalendarOptions'],
    'eval' => ['includeBlankOption' => true, 'tl_class' => 'w50', 'chosen' => true],
    'sql' => "varchar(255) NOT NULL default ''",
];

$GLOBALS['TL_DCA']['tl_calendar']['fields']['google_calendar_id_export'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_calendar']['google_calendar_id_export'],
    'inputType' => 'select',
    'options_callback' => ['tl_calendar_google', 'getGoogleCalendarOptions'],
    'eval' => ['includeBlankOption' => true, 'tl_class' => 'w50', 'chosen' => true],
    'sql' => "varchar(255) NOT NULL default ''",
];

$GLOBALS['TL_DCA']['tl_calendar']['fields']['google_last_sync'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_calendar']['google_last_sync'],
    'inputType' => 'text',
    'eval' => ['rgxp' => 'datim', 'tl_class' => 'w50', 'readonly' => true],
    'sql' => "int(10) unsigned NOT NULL default 0",
];

$GLOBALS['TL_DCA']['tl_calendar']['fields']['google_sync_as_busy'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_calendar']['google_sync_as_busy'],
    'inputType' => 'checkbox',
    'eval' => ['submitOnChange' => true, 'tl_class' => 'w50 m12'],
    'sql' => "char(1) NOT NULL default ''",
];

$GLOBALS['TL_DCA']['tl_calendar']['fields']['google_sync_busy_text'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_calendar']['google_sync_busy_text'],
    'inputType' => 'text',
    'eval' => ['maxlength' => 255, 'tl_class' => 'w50'],
    'sql' => "varchar(255) NOT NULL default ''",
];

$GLOBALS['TL_DCA']['tl_calendar']['fields']['google_sync_until'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_calendar']['google_sync_until'],
    'inputType' => 'text',
    'default' => strtotime('+1 year'),
    'eval' => ['rgxp' => 'date', 'datepicker' => true, 'tl_class' => 'w50 wizard'],
    'sql' => "int(10) unsigned NOT NULL default 0",
];

// Add fields to palette
PaletteManipulator::create()
    ->addLegend('google_sync_legend', 'title_legend', PaletteManipulator::POSITION_AFTER)
    ->addField(['google_sync_enabled'], 'google_sync_legend', PaletteManipulator::POSITION_APPEND)
    ->applyToPalette('default', 'tl_calendar');

// Create subpalette for when google_sync_enabled is checked
$GLOBALS['TL_DCA']['tl_calendar']['palettes']['__selector__'][] = 'google_sync_enabled';
$GLOBALS['TL_DCA']['tl_calendar']['palettes']['__selector__'][] = 'google_sync_as_busy';
$GLOBALS['TL_DCA']['tl_calendar']['subpalettes']['google_sync_enabled'] = 'google_calendar_id_import,google_calendar_id_export,google_sync_until,google_sync_as_busy,google_last_sync';
$GLOBALS['TL_DCA']['tl_calendar']['subpalettes']['google_sync_as_busy'] = 'google_sync_busy_text';


// Add global operation button for syncing all calendars
$GLOBALS['TL_DCA']['tl_calendar']['list']['global_operations']['google_sync_all'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_calendar']['google_sync_all'],
    'href' => '',
    'icon' => '/bundles/fouranglescontaogooglecalendar/icons/sync-calendar.svg',
    'button_callback' => ['tl_calendar_google', 'syncAllButton'],
];

/**
 * Provide miscellaneous methods for tl_calendar with Google Calendar integration
 */
class tl_calendar_google extends Backend
{
    /**
     * Get Google Calendar options
     */
    public function getGoogleCalendarOptions($dc)
    {
        try {
            $googleService = System::getContainer()->get('FourAngles\ContaoGoogleCalendarBundle\Service\GoogleCalendarService');
            $calendars = $googleService->getCalendarList();
            
            $options = [];
            foreach ($calendars as $calendar) {
                $label = $calendar['summary'];
                if ($calendar['primary']) {
                    $label .= ' (Primary)';
                }
                $options[$calendar['id']] = $label;
            }
            
            return $options;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Return global sync all button
     */
    public function syncAllButton()
    {
        $url = '/contao/google-calendar-sync-all';
        $title = $GLOBALS['TL_LANG']['tl_calendar']['google_sync_all'][1] ?? 'Sync all calendars';
        $label = $GLOBALS['TL_LANG']['tl_calendar']['google_sync_all'][0] ?? 'Sync All';
        $icon = '/bundles/fouranglescontaogooglecalendar/icons/sync-calendar.svg';
        $confirm = $GLOBALS['TL_LANG']['tl_calendar']['google_sync_all_confirm'] ?? 'Sync all calendars with Google?';
        
        return '<a href="' . $url . '" title="' . \Contao\StringUtil::specialchars($title) . '" onclick="if(!confirm(\'' . \Contao\StringUtil::specialchars($confirm) . '\'))return false">' . \Contao\Image::getHtml($icon, $label) . ' ' . $label . '</a> ';
    }
}
