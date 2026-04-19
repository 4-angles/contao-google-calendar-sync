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

// Track origin of last sync: 'contao' = edited in Contao, 'google' = imported from Google
// Used to prevent sync loops when same calendar is used for both import and export
$GLOBALS['TL_DCA']['tl_calendar_events']['fields']['google_event_origin'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_calendar_events']['google_event_origin'],
    'inputType' => 'text',
    'eval' => ['tl_class' => 'w50', 'readonly' => true],
    'sql' => "varchar(16) NOT NULL default 'contao'",
];

// Store the Google Calendar ID from which this event was imported
// Used to prevent exporting back to the same calendar (sync loop prevention)
$GLOBALS['TL_DCA']['tl_calendar_events']['fields']['google_calendar_source'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_calendar_events']['google_calendar_source'],
    'inputType' => 'text',
    'eval' => ['tl_class' => 'w50', 'readonly' => true],
    'sql' => "varchar(255) NOT NULL default ''",
];

// Store the event ID in the export calendar (different from google_event_id which is the import ID)
$GLOBALS['TL_DCA']['tl_calendar_events']['fields']['google_export_event_id'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_calendar_events']['google_export_event_id'],
    'inputType' => 'text',
    'eval' => ['tl_class' => 'w50', 'readonly' => true],
    'sql' => "varchar(255) NOT NULL default ''",
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
// Reset origin to 'contao' when user edits an event (so changes will be exported)
$GLOBALS['TL_DCA']['tl_calendar_events']['config']['onsubmit_callback'][] = ['tl_calendar_events_google', 'resetOriginOnUserEdit'];
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
    'icon' => '/bundles/fouranglescontaogooglecalendar/icons/sync-calendar.svg',
    'button_callback' => ['tl_calendar_events_google', 'importFromGoogleButton']
];

$GLOBALS['TL_DCA']['tl_calendar_events']['list']['global_operations']['export_to_google'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_calendar_events']['export_to_google'],
    'href' => 'key=export_to_google',
    'class' => 'header_sync',
    'icon' => '/bundles/fouranglescontaogooglecalendar/icons/sync-calendar.svg',
    'button_callback' => ['tl_calendar_events_google', 'exportToGoogleButton']
];

// Override the toggle operation - button_callback generates a URL to our controller
// so act=toggle never reaches Contao's DC_Table (which mixes up the calendar parent id with the event id)
if (isset($GLOBALS['TL_DCA']['tl_calendar_events']['list']['operations']['toggle'])) {
    $GLOBALS['TL_DCA']['tl_calendar_events']['list']['operations']['toggle']['button_callback'] = ['tl_calendar_events_google', 'toggleVisibilityIcon'];
}

/**
 * Provide miscellaneous methods for tl_calendar_events with Google Calendar integration
 */
class tl_calendar_events_google
{
    /**
     * Handle toggle visibility button click - sync publish/unpublish with Google Calendar
     */
    public function toggleVisibilityIcon($row, $href, $label, $title, $icon, $attributes)
    {
        $isPublished = $row['published'];
        $iconImg = $isPublished ? 'visible.svg' : 'invisible.svg';
        $newState = $isPublished ? 0 : 1;

        // Route through our controller so act=toggle never hits Contao's DC_Table
        $url = \Contao\System::getContainer()->get('router')->generate(
            'google_calendar_toggle_event',
            ['id' => $row['id'], 'state' => $newState]
        );

        return '<a href="' . $url . '" title="' . \Contao\StringUtil::specialchars($title) . '"' . $attributes . '>' . \Contao\Image::getHtml($iconImg, $label) . '</a> ';
    }
    
    /**
     * Reset origin to 'contao' when user edits an event via backend
     * This ensures user changes will be exported to Google
     */
    public function resetOriginOnUserEdit(DataContainer $dc)
    {
        if (!$dc->id) {
            return;
        }
        
        $event = \Contao\CalendarEventsModel::findByPk($dc->id);
        if (!$event) {
            return;
        }
        
        // If event was imported from Google and user is editing it,
        // change origin to 'contao' so their changes will be exported
        if ($event->google_event_origin === 'google') {
            $event->google_event_origin = 'contao';
            $event->save();
        }
    }
    
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
        if (!$calendar || !$calendar->google_sync_enabled || !$calendar->google_calendar_id_import) {
            return '';
        }
        
        // Generate the button HTML with direct link to controller
        $url = \Contao\System::getContainer()->get('router')->generate('google_calendar_import_events', ['id' => $calendarId]);
        return '<a href="' . $url . '" class="' . $class . '" title="' . \Contao\StringUtil::specialchars($title) . '"' . $attributes . '>' . $label . '</a> ';
    }
    
    /**
     * Show export to Google button only if calendar has sync enabled
     */
    public function exportToGoogleButton($href, $label, $title, $class, $attributes)
    {
        // Get current calendar ID from request
        $calendarId = \Contao\Input::get('id');
        
        if (!$calendarId) {
            return '';
        }
        
        // Check if calendar has Google sync enabled with export calendar
        $calendar = \Contao\CalendarModel::findByPk($calendarId);
        if (!$calendar || !$calendar->google_sync_enabled || !$calendar->google_calendar_id_export) {
            return '';
        }
        
        // Generate the button HTML with direct link to controller
        $url = \Contao\System::getContainer()->get('router')->generate('google_calendar_export_events', ['id' => $calendarId]);
        return '<a href="' . $url . '" class="' . $class . '" title="' . \Contao\StringUtil::specialchars($title) . '"' . $attributes . '>' . $label . '</a> ';
    }
    
    /**
     * Add sync icon to event list
     */
    public function addSyncIcon($row, $label, $dc, $args)
    {
        if ($row['google_event_id']) {
            $label .= ' <img src="/bundles/fouranglescontaogooglecalendar/icons/sync-calendar.svg" width="16" height="16" alt="Imported from Google" title="Imported from Google Calendar">';
        }
        if ($row['google_export_event_id']) {
            $label .= ' <img src="/bundles/fouranglescontaogooglecalendar/icons/sync-calendar.svg" width="16" height="16" alt="Exported to Google" title="Exported to Google Calendar" style="filter: hue-rotate(180deg);">';
        }
        
        return $label;
    }
    
    /**
     * Triggered when published field is toggled
     */
    public function onPublishedToggle($value, DataContainer $dc)
    {
        $logger = \Contao\System::getContainer()->get('monolog.logger.contao.general');
        $logger->info('onPublishedToggle called', [
            'event_id' => $dc->id ?? 'null',
            'new_value' => $value
        ]);
        
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
        
        $logger->info('onPublishedToggle event found', [
            'event_id' => $event->id,
            'old_published' => $oldPublished,
            'new_value' => $value,
            'google_export_event_id' => $event->google_export_event_id
        ]);
        
        // Get parent calendar
        $calendar = \Contao\CalendarModel::findByPk($event->pid);
        if (!$calendar || !$calendar->google_sync_enabled || !$calendar->google_calendar_id_export) {
            $logger->info('onPublishedToggle - sync not enabled or no export calendar');
            return $value;
        }
        
        // Get the Google Calendar service
        try {
            $googleService = \Contao\System::getContainer()->get('FourAngles\ContaoGoogleCalendarBundle\Service\GoogleCalendarService');
            
            // Check if value changed from published to unpublished
            $isNowUnpublished = empty($value) || $value === '0' || $value === 0 || $value === false;
            $wasPublished = !empty($oldPublished);
            
            $logger->info('onPublishedToggle checking state change', [
                'isNowUnpublished' => $isNowUnpublished ? 'yes' : 'no',
                'wasPublished' => $wasPublished ? 'yes' : 'no',
                'has_export_id' => !empty($event->google_export_event_id) ? 'yes' : 'no'
            ]);
            
            // If unpublished and has export ID, delete from export calendar
            if ($isNowUnpublished && $wasPublished && $event->google_export_event_id) {
                $logger->info('DELETING from export calendar', [
                    'export_id' => $event->google_export_event_id,
                    'calendar' => $calendar->google_calendar_id_export
                ]);
                $googleService->deleteEventFromGoogle($event->google_export_event_id, $calendar->google_calendar_id_export);
                // Schedule cleanup of google_export_event_id
                $GLOBALS['TL_DCA']['tl_calendar_events']['_google_cleanup'][$dc->id] = true;
            }
            // If published (and wasn't before OR needs update), sync to Google
            elseif (!$isNowUnpublished) {
                $googleEventId = $googleService->syncEventToGoogle($event, $calendar->google_calendar_id_export, $event->google_export_event_id ?: null);
                if ($googleEventId) {
                    // Schedule update of google_export_event_id
                    $GLOBALS['TL_DCA']['tl_calendar_events']['_google_update'][$dc->id] = $googleEventId;
                }
            }
        } catch (\Exception $e) {
            // Log error but don't block the save
            \Contao\System::getContainer()->get('monolog.logger.contao.general')->error('Error syncing event on publish toggle: ' . $e->getMessage());
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
        
        // Check if we need to cleanup or update google_export_event_id
        if (isset($GLOBALS['TL_DCA']['tl_calendar_events']['_google_cleanup'][$dc->id])) {
            $event = \Contao\CalendarEventsModel::findByPk($dc->id);
            if ($event) {
                $event->google_export_event_id = '';
                $event->google_updated = 0;
                $event->save();
            }
            unset($GLOBALS['TL_DCA']['tl_calendar_events']['_google_cleanup'][$dc->id]);
        }
        
        if (isset($GLOBALS['TL_DCA']['tl_calendar_events']['_google_update'][$dc->id])) {
            $googleEventId = $GLOBALS['TL_DCA']['tl_calendar_events']['_google_update'][$dc->id];
            $event = \Contao\CalendarEventsModel::findByPk($dc->id);
            if ($event) {
                $event->google_export_event_id = $googleEventId;
                $event->google_updated = time();
                $event->save();
            }
            unset($GLOBALS['TL_DCA']['tl_calendar_events']['_google_update'][$dc->id]);
        }
    }
}