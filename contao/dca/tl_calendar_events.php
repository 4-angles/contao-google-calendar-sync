<?php

// contao/dca/tl_calendar_events.php
// Extend tl_calendar_events with Google Calendar tracking fields (read-only)
// Sync settings are controlled at the calendar level (tl_calendar)

use Contao\DataContainer;

// Add fields to database - these are tracking fields only
$GLOBALS['TL_DCA']['tl_calendar_events']['fields']['google_event_id'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_calendar_events']['google_event_id'],
    'inputType' => 'text',
    'eval' => ['maxlength' => 255, 'tl_class' => 'w50', 'readonly' => true],
    'sql' => "varchar(255) NOT NULL default ''",
];

$GLOBALS['TL_DCA']['tl_calendar_events']['fields']['google_updated'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_calendar_events']['google_updated'],
    'inputType' => 'text',
    'eval' => ['rgxp' => 'digit', 'tl_class' => 'w50', 'readonly' => true],
    'sql' => "int(10) unsigned NOT NULL default 0",
];

// Add save_callback to published field to trigger sync on toggle
if (isset($GLOBALS['TL_DCA']['tl_calendar_events']['fields']['published'])) {
    if (!isset($GLOBALS['TL_DCA']['tl_calendar_events']['fields']['published']['save_callback'])) {
        $GLOBALS['TL_DCA']['tl_calendar_events']['fields']['published']['save_callback'] = [];
    }
    $GLOBALS['TL_DCA']['tl_calendar_events']['fields']['published']['save_callback'][] = ['tl_calendar_events_google', 'onPublishedToggle'];
}

// Add onsubmit callback to finalize Google Calendar ID updates after save
if (!isset($GLOBALS['TL_DCA']['tl_calendar_events']['config']['onsubmit_callback'])) {
    $GLOBALS['TL_DCA']['tl_calendar_events']['config']['onsubmit_callback'] = [];
}
$GLOBALS['TL_DCA']['tl_calendar_events']['config']['onsubmit_callback'][] = ['tl_calendar_events_google', 'finalizeGoogleSync'];

// Add list label callback to show sync status
$GLOBALS['TL_DCA']['tl_calendar_events']['list']['label']['label_callback'] = ['tl_calendar_events_google', 'addSyncIcon'];

// Add global operation to import from Google Calendar
if (!isset($GLOBALS['TL_DCA']['tl_calendar_events']['list']['global_operations'])) {
    $GLOBALS['TL_DCA']['tl_calendar_events']['list']['global_operations'] = [];
}

$GLOBALS['TL_DCA']['tl_calendar_events']['list']['global_operations']['import_from_google'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_calendar_events']['import_from_google'],
    'href' => 'key=import_from_google',
    'class' => 'header_sync',
    'icon' => '/sync-calendar.svg',
    'button_callback' => ['tl_calendar_events_google', 'importFromGoogleButton']
];

/**
 * Provide miscellaneous methods for tl_calendar_events with Google Calendar integration
 */
class tl_calendar_events_google
{
    /**
     * Show import from Google button only if calendar has sync enabled
     */
    public function importFromGoogleButton($href, $label, $title, $class, $attributes)
    {
        // Get current calendar ID from request
        $calendarId = \Contao\Input::get('id');
        
        if (!$calendarId) {
            return '';
        }
        
        // Check if calendar has Google sync enabled
        $calendar = \Contao\CalendarModel::findByPk($calendarId);
        if (!$calendar || !$calendar->google_sync_enabled || !$calendar->google_calendar_id) {
            return '';
        }
        
        // Only show if sync direction allows importing from Google
        if ($calendar->google_sync_direction === 'to_google') {
            return '';
        }
        
        // Generate the button HTML with direct link to controller
        $url = \Contao\System::getContainer()->get('router')->generate('google_calendar_import_events', ['id' => $calendarId]);
        return '<a href="' . $url . '" class="' . $class . '" title="' . \Contao\StringUtil::specialchars($title) . '"' . $attributes . '>' . $label . '</a> ';
    }
    
    /**
     * Add sync icon to event list
     */
    public function addSyncIcon($row, $label, $dc, $args)
    {
        if ($row['google_event_id']) {
            $label .= ' <img src="/sync-calendar.svg" width="16" height="16" alt="Synced with Google" title="Synced with Google Calendar">';
        }
        
        return $label;
    }
    
    /**
     * Triggered when published field is toggled
     */
    public function onPublishedToggle($value, DataContainer $dc)
    {
        // If no ID, return value as-is (during creation)
        if (!$dc->id) {
            return $value;
        }
        
        // Get the event
        $event = \Contao\CalendarEventsModel::findByPk($dc->id);
        if (!$event) {
            return $value;
        }
        
        // Store the old published state for comparison
        $oldPublished = $event->published;
        
        // Get parent calendar
        $calendar = \Contao\CalendarModel::findByPk($event->pid);
        if (!$calendar || !$calendar->google_sync_enabled || !$calendar->google_calendar_id) {
            return $value;
        }
        
        // Only sync if direction allows Contao → Google
        if ($calendar->google_sync_direction === 'from_google') {
            return $value;
        }
        
        // Get the Google Calendar service
        try {
            $googleService = \Contao\System::getContainer()->get('FourAngles\ContaoGoogleCalendarBundle\Service\GoogleCalendarService');
            
            // Check if value changed from published to unpublished
            $isNowUnpublished = empty($value) || $value === '0' || $value === 0 || $value === false;
            $wasPublished = !empty($oldPublished);
            
            // If unpublished and has Google Calendar ID, delete from Google
            if ($isNowUnpublished && $wasPublished && $event->google_event_id) {
                $googleService->deleteEventFromGoogle($event->google_event_id, $calendar->google_calendar_id);
                // Schedule cleanup of google_event_id
                $GLOBALS['TL_DCA']['tl_calendar_events']['_google_cleanup'][$dc->id] = true;
            }
            // If published (and wasn't before OR needs update), sync to Google
            elseif (!$isNowUnpublished) {
                $googleEventId = $googleService->syncEventToGoogle($event, $calendar->google_calendar_id);
                if ($googleEventId) {
                    // Schedule update of google_event_id
                    $GLOBALS['TL_DCA']['tl_calendar_events']['_google_update'][$dc->id] = $googleEventId;
                }
            }
        } catch (\Exception $e) {
            // Log error but don't block the save
            \Contao\System::getContainer()->get('logger')->error('Error syncing event on publish toggle: ' . $e->getMessage());
        }
        
        return $value;
    }
    
    /**
     * Finalize Google Calendar sync after main save is complete
     */
    public function finalizeGoogleSync(DataContainer $dc)
    {
        if (!$dc->id) {
            return;
        }
        
        // Check if we need to cleanup or update google_event_id
        if (isset($GLOBALS['TL_DCA']['tl_calendar_events']['_google_cleanup'][$dc->id])) {
            $event = \Contao\CalendarEventsModel::findByPk($dc->id);
            if ($event) {
                $event->google_event_id = '';
                $event->google_updated = 0;
                $event->save();
            }
            unset($GLOBALS['TL_DCA']['tl_calendar_events']['_google_cleanup'][$dc->id]);
        }
        
        if (isset($GLOBALS['TL_DCA']['tl_calendar_events']['_google_update'][$dc->id])) {
            $googleEventId = $GLOBALS['TL_DCA']['tl_calendar_events']['_google_update'][$dc->id];
            $event = \Contao\CalendarEventsModel::findByPk($dc->id);
            if ($event) {
                $event->google_event_id = $googleEventId;
                $event->google_updated = time();
                $event->save();
            }
            unset($GLOBALS['TL_DCA']['tl_calendar_events']['_google_update'][$dc->id]);
        }
    }
}