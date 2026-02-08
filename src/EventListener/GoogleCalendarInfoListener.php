<?php

namespace App\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;

/**
 * Add helpful messages to Google Calendar settings
 */
class GoogleCalendarInfoListener
{
    #[AsCallback(table: 'tl_calendar', target: 'config.onload')]
    public function onLoadCalendar(DataContainer $dc): void
    {
        // Add information message when editing calendar
        if ($dc->id && \Contao\Input::get('act') === 'edit') {
            $calendar = \Contao\CalendarModel::findByPk($dc->id);
            
            if ($calendar && $calendar->google_sync_enabled && !$calendar->google_calendar_id) {
                \Contao\Message::addInfo(
                    'Please select a Google Calendar to sync with. If no calendars are shown, ' .
                    'visit <strong>/contao/google-calendar-auth</strong> to authenticate with Google.'
                );
            }
        }
    }
}
