<?php

namespace FourAngles\ContaoGoogleCalendarBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Symfony\Component\Routing\RouterInterface;

/**
 * Add helpful messages to Google Calendar settings
 */
class GoogleCalendarInfoListener
{
    public function __construct(
        private readonly RouterInterface $router
    ) {
    }

    #[AsCallback(table: 'tl_calendar', target: 'config.onload')]
    public function onLoadCalendar(DataContainer $dc): void
    {
        // Add information message when editing calendar
        if ($dc->id && \Contao\Input::get('act') === 'edit') {
            $calendar = \Contao\CalendarModel::findByPk($dc->id);
            
            if ($calendar && $calendar->google_sync_enabled && !$calendar->google_calendar_id_import && !$calendar->google_calendar_id_export) {
                $authUrl = $this->router->generate('google_calendar_auth');
                \Contao\Message::addInfo(
                    'Please select at least one Google Calendar (import or export). If no calendars are shown, ' .
                    'visit <strong><a href="' . $authUrl . '">' . $authUrl . '</a></strong> to authenticate with Google.'
                );
            }
        }
    }
}
