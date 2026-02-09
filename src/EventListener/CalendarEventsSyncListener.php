<?php

namespace FourAngles\ContaoGoogleCalendarBundle\EventListener;

use FourAngles\ContaoGoogleCalendarBundle\Service\GoogleCalendarService;
use Contao\CalendarEventsModel;
use Contao\CalendarModel;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\System;
use Psr\Log\LoggerInterface;

/**
 * Event listener for tl_calendar_events to sync with Google Calendar
 */
class CalendarEventsSyncListener
{
    private GoogleCalendarService $googleService;
    private LoggerInterface $logger;

    public function __construct(GoogleCalendarService $googleService, LoggerInterface $logger)
    {
        $this->googleService = $googleService;
        $this->logger = $logger;
    }

    /**
     * Triggered when a calendar event is saved
     */
    #[AsCallback(table: 'tl_calendar_events', target: 'config.onsubmit')]
    public function onSubmitCalendarEvent(DataContainer $dc): void
    {


        if (!$dc->id) {
            return;
        }

        $event = CalendarEventsModel::findByPk($dc->id);
        if (!$event) {
            return;
        }

        // Get parent calendar and check if sync is enabled at calendar level
        $calendar = CalendarModel::findByPk($event->pid);
        if (!$calendar || !$calendar->google_sync_enabled || !$calendar->google_calendar_id_export) {
            return;
        }

        // If event is unpublished and has a Google Calendar ID, delete it from Google
        if (!$event->published && $event->google_event_id) {
            $this->googleService->deleteEventFromGoogle($event->google_event_id, $calendar->google_calendar_id_export);
            $event->google_event_id = '';
            $event->save();
            return;
        }
        
        // If recurring event has ended, delete from Google Calendar
        if ($event->recurring && $event->repeatEnd > 0 && $event->repeatEnd < time() && $event->google_event_id) {
            $this->googleService->deleteEventFromGoogle($event->google_event_id, $calendar->google_calendar_id_export);
            $event->google_event_id = '';
            $event->save();
            $this->logger->info('Deleted expired recurring event from Google Calendar', [
                'event_id' => $event->id,
                'event_title' => $event->title,
                'repeat_end' => date('Y-m-d H:i:s', $event->repeatEnd)
            ]);
            return;
        }

        // Skip unpublished events
        if (!$event->published) {
            return;
        }

            
        // Sync to Google Calendar
        $googleEventId = $this->googleService->syncEventToGoogle($event, $calendar->google_calendar_id_export);
        if ($googleEventId) {
            // Update event with Google Calendar ID
            $event->google_event_id = $googleEventId;
            $event->google_updated = time();
            $event->save();

            $this->logger->info('Event synced to Google Calendar', [
                'event_id' => $event->id,
                'event_title' => $event->title,
                'google_event_id' => $googleEventId
            ]);
        }
    }

    /**
     * Triggered when a calendar event is deleted
     */
    #[AsCallback(table: 'tl_calendar_events', target: 'config.ondelete')]
    public function onDeleteCalendarEvent(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        $event = CalendarEventsModel::findByPk($dc->id);
        if (!$event || !$event->google_event_id) {
            return;
        }

        // Get parent calendar
        $calendar = CalendarModel::findByPk($event->pid);
        if (!$calendar || !$calendar->google_sync_enabled || !$calendar->google_calendar_id_export) {
            return;
        }

        // Delete from Google Calendar
        $success = $this->googleService->deleteEventFromGoogle(
            $event->google_event_id,
            $calendar->google_calendar_id_export
        );

        if ($success) {
            $this->logger->info('Event "' . $event->title . '" deleted from Google Calendar');
        }
    }

    /**
     * Triggered when calendar event is copied
     */
    #[AsCallback(table: 'tl_calendar_events', target: 'config.oncopy')]
    public function onCopyCalendarEvent(int $insertId, DataContainer $dc): void
    {
        // Reset Google Calendar fields for copied event
        $event = CalendarEventsModel::findByPk($insertId);
        if ($event) {
            $event->google_event_id = '';
            $event->google_updated = 0;
            $event->save();
        }
    }
}
