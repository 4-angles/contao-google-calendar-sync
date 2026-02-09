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

$GLOBALS['TL_DCA']['tl_calendar']['fields']['google_calendar_id'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_calendar']['google_calendar_id'],
    'inputType' => 'select',
    'options_callback' => ['tl_calendar_google', 'getGoogleCalendarOptions'],
    'eval' => ['includeBlankOption' => true, 'tl_class' => 'w50', 'chosen' => true],
    'sql' => "varchar(255) NOT NULL default ''",
];

$GLOBALS['TL_DCA']['tl_calendar']['fields']['google_sync_direction'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_calendar']['google_sync_direction'],
    'inputType' => 'select',
    'reference' => &$GLOBALS['TL_LANG']['tl_calendar'],
    'options' => ['to_google','from_google'],
    'eval' => ['tl_class' => 'w50'],
    'sql' => "varchar(32) NOT NULL default 'to_google'",
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
    'eval' => ['tl_class' => 'w50 m12'],
    'sql' => "char(1) NOT NULL default ''",
];

// Add fields to palette
PaletteManipulator::create()
    ->addLegend('google_sync_legend', 'title_legend', PaletteManipulator::POSITION_AFTER)
    ->addField(['google_sync_enabled'], 'google_sync_legend', PaletteManipulator::POSITION_APPEND)
    ->applyToPalette('default', 'tl_calendar');

// Create subpalette for when google_sync_enabled is checked
$GLOBALS['TL_DCA']['tl_calendar']['palettes']['__selector__'][] = 'google_sync_enabled';
$GLOBALS['TL_DCA']['tl_calendar']['subpalettes']['google_sync_enabled'] = 'google_calendar_id,google_sync_direction,google_sync_as_busy,google_last_sync';


// Add global operation button for syncing all calendars
$GLOBALS['TL_DCA']['tl_calendar']['list']['global_operations']['google_sync_all'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_calendar']['google_sync_all'],
    'href' => '',
    'icon' => '/sync-calendar.svg',
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
     * Return sync button only if sync is enabled
     */
    public function syncButton($row, $href, $label, $title, $icon, $attributes)
    {
        if (!$row['google_sync_enabled'] || !$row['google_calendar_id']) {
            return '';
        }

        $url = '/contao/google-calendar-sync?id=' . $row['id'];
        return '<a href="' . $url . '" title="' . \Contao\StringUtil::specialchars($title) . '"' . $attributes . '>' . \Contao\Image::getHtml($icon, $label) . '</a> ';
    }
    
    /**
     * Return drop all button only if sync is enabled
     */
    public function dropAllButton($row, $href, $label, $title, $icon, $attributes)
    {
        if (!$row['google_sync_enabled'] || !$row['google_calendar_id']) {
            return '';
        }

        $url = '/contao/google-calendar-drop-all?id=' . $row['id'] . '&managed=1';
        $confirm = $GLOBALS['TL_LANG']['tl_calendar']['google_drop_all_confirm'] ?? 'Delete all managed events from Google Calendar?';
        return '<a href="' . $url . '" title="' . \Contao\StringUtil::specialchars($title) . '" onclick="if(!confirm(\'' . \Contao\StringUtil::specialchars($confirm) . '\'))return false"' . $attributes . '>' . \Contao\Image::getHtml($icon, $label) . '</a> ';
    }

    /**
     * Return global sync all button
     */
    public function syncAllButton()
    {
        $url = '/contao/google-calendar-sync-all';
        $title = $GLOBALS['TL_LANG']['tl_calendar']['google_sync_all'][1] ?? 'Sync all calendars';
        $label = $GLOBALS['TL_LANG']['tl_calendar']['google_sync_all'][0] ?? 'Sync All';
        $icon = '/sync-calendar.svg';
        $confirm = $GLOBALS['TL_LANG']['tl_calendar']['google_sync_all_confirm'] ?? 'Sync all calendars with Google?';
        
        return '<a href="' . $url . '" title="' . \Contao\StringUtil::specialchars($title) . '" onclick="if(!confirm(\'' . \Contao\StringUtil::specialchars($confirm) . '\'))return false">' . \Contao\Image::getHtml($icon, $label) . ' ' . $label . '</a> ';
    }
}
