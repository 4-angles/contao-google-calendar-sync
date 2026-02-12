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
     * Triggered when a new calendar event is created
     */
    #[AsCallback(table: 'tl_calendar_events', target: 'config.oncreate')]
    public function onCreateCalendarEvent(string $table, int $insertId, array $set, DataContainer $dc): void
    {
        // Wait a moment for the database transaction to complete
        usleep(100000); // 100ms
        
        $this->syncEventToGoogleCalendar($insertId);
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

        $this->syncEventToGoogleCalendar($dc->id);
    }

    /**
     * Sync event to Google Calendar
     */
    private function syncEventToGoogleCalendar(int $eventId): void
    {
        $event = CalendarEventsModel::findByPk($eventId);
        if (!$event) {
            return;
        }

        // Get parent calendar and check if sync is enabled at calendar level
        $calendar = CalendarModel::findByPk($event->pid);
        if (!$calendar || !$calendar->google_sync_enabled || !$calendar->google_calendar_id_export) {
            return;
        }

        $this->logger->info('Syncing calendar event', [
            'event_id' => $event->id,
            'published' => $event->published,
            'google_export_event_id' => $event->google_export_event_id
        ]);

        try {
            // If event is unpublished and has an export ID, delete it from export calendar
            if (!$event->published && $event->google_export_event_id) {
                $this->logger->info('Unpublishing event - deleting from export calendar', [
                    'event_id' => $event->id,
                    'google_export_event_id' => $event->google_export_event_id,
                    'export_calendar' => $calendar->google_calendar_id_export
                ]);
                $success = $this->googleService->deleteEventFromGoogle($event->google_export_event_id, $calendar->google_calendar_id_export);
                if ($success) {
                    $event->google_export_event_id = '';
                    $event->save();
                    $this->logger->info('Successfully deleted unpublished event from export calendar');
                    \Contao\Message::addInfo('Event removed from Google Calendar');
                } else {
                    $this->logger->error('Failed to delete unpublished event from export calendar');
                    \Contao\Message::addError('Failed to remove event from Google Calendar');
                }
                return;
            }
            
            // If recurring event has ended, delete from export calendar
            if ($event->recurring && $event->repeatEnd > 0 && $event->repeatEnd < time() && $event->google_export_event_id) {
                $this->googleService->deleteEventFromGoogle($event->google_export_event_id, $calendar->google_calendar_id_export);
                $event->google_export_event_id = '';
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

            // Sync to Google Calendar (use existing export ID if available)
            $googleEventId = $this->googleService->syncEventToGoogle($event, $calendar->google_calendar_id_export, $event->google_export_event_id ?: null);
            if ($googleEventId) {
                // Update event with export Google Calendar ID
                $event->google_export_event_id = $googleEventId;
                $event->google_updated = time();
                // Only set origin to 'contao' if it wasn't imported from Google
                if ($event->google_event_origin !== 'google') {
                    $event->google_event_origin = 'contao';
                }
                $event->save();

                $this->logger->info('Event synced to Google Calendar', [
                    'event_id' => $event->id,
                    'event_title' => $event->title,
                    'google_export_event_id' => $googleEventId
                ]);
                
                \Contao\Message::addConfirmation('Event exported to Google Calendar');
            } else {
                $this->logger->error('Failed to sync event to Google Calendar');
                \Contao\Message::addError('Failed to export event to Google Calendar');
            }
        } catch (\Exception $e) {
            $this->logger->error('Error during event sync', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            \Contao\Message::addError('Error exporting to Google Calendar: ' . $e->getMessage());
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
        if (!$event || !$event->google_export_event_id) {
            return;
        }

        // Get parent calendar
        $calendar = CalendarModel::findByPk($event->pid);
        if (!$calendar || !$calendar->google_sync_enabled || !$calendar->google_calendar_id_export) {
            return;
        }

        // Delete from export calendar
        $success = $this->googleService->deleteEventFromGoogle(
            $event->google_export_event_id,
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
            $event->google_export_event_id = '';
            $event->google_updated = 0;
            $event->save();
        }
    }
}
