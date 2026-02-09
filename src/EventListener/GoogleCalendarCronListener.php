<?php

namespace FourAngles\ContaoGoogleCalendarBundle\EventListener;

use FourAngles\ContaoGoogleCalendarBundle\Service\GoogleCalendarService;
use Contao\CalendarEventsModel;
use Contao\CalendarModel;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Psr\Log\LoggerInterface;

/**
 * Cron job for automatic Google Calendar sync
 */
class GoogleCalendarCronListener
{
    private GoogleCalendarService $googleService;
    private LoggerInterface $logger;

    public function __construct(GoogleCalendarService $googleService, LoggerInterface $logger)
    {
        $this->googleService = $googleService;
        $this->logger = $logger;
    }

    /**
     * Run every minute - import events from Google
     */
    #[AsHook('minutely')]
    public function onMinutely(): void
    {
        $this->importFromGoogle();
    }

    /**
     * Run on hourly cron - sync events to Google
     */
    #[AsHook('hourly')]
    public function onHourly(): void
    {
        $this->syncToGoogle();
    }

    /**
     * Import events from Google Calendar for all enabled calendars
     */
    private function importFromGoogle(): void
    {
        $calendars = CalendarModel::findBy('google_sync_enabled', '1');

        if (!$calendars) {
            return;
        }

        foreach ($calendars as $calendar) {
            if (!$calendar->google_calendar_id) {
                continue;
            }

            $direction = $calendar->google_sync_direction;

            if ($direction !== 'from_google' && $direction !== 'bidirectional') {
                continue;
            }

            try {
                $syncCount = $this->googleService->syncFromGoogle($calendar, $calendar->google_calendar_id);

                $calendar->google_last_sync = time();
                $calendar->save();

                if ($syncCount > 0) {
                    $this->logger->info("Imported $syncCount events from Google for '{$calendar->title}'");
                }
            } catch (\Exception $e) {
                $this->logger->error("Google Calendar import error for '{$calendar->title}': {$e->getMessage()}");
            }
        }
    }

    /**
     * Sync events to Google Calendar for all enabled calendars
     */
    private function syncToGoogle(): void
    {
        $calendars = CalendarModel::findBy('google_sync_enabled', '1');

        if (!$calendars) {
            return;
        }

        foreach ($calendars as $calendar) {
            if (!$calendar->google_calendar_id) {
                continue;
            }

            try {
                $direction = $calendar->google_sync_direction;
                $syncCount = 0;

                // Sync TO Google
                if ($direction === 'to_google' || $direction === 'bidirectional') {
                    $events = CalendarEventsModel::findBy('pid', $calendar->id);
                    
                    if ($events) {
                        foreach ($events as $event) {
                            // If event is unpublished/hidden and has a Google Calendar ID, delete it
                            if (!$event->published && $event->google_event_id) {
                                $this->googleService->deleteEventFromGoogle(
                                    $event->google_event_id,
                                    $calendar->google_calendar_id
                                );
                                $event->google_event_id = '';
                                $event->save();
                                $syncCount++;
                                continue;
                            }
                            
                            // If recurring event has ended, delete from Google Calendar
                            if ($event->recurring && $event->repeatEnd > 0 && $event->repeatEnd < time() && $event->google_event_id) {
                                $this->googleService->deleteEventFromGoogle(
                                    $event->google_event_id,
                                    $calendar->google_calendar_id
                                );
                                $event->google_event_id = '';
                                $event->save();
                                $syncCount++;
                                continue;
                            }
                            
                            // Skip unpublished events
                            if (!$event->published) {
                                continue;
                            }
                            
                            // Only sync if modified since last sync
                            if ($calendar->google_last_sync && $event->tstamp <= $calendar->google_last_sync) {
                                continue;
                            }

                            $googleEventId = $this->googleService->syncEventToGoogle(
                                $event,
                                $calendar->google_calendar_id
                            );
                            
                            if ($googleEventId) {
                                $event->google_event_id = $googleEventId;
                                $event->google_updated = time();
                                $event->save();
                                $syncCount++;
                            }
                        }
                    }
                }

                // Update last sync timestamp
                $calendar->google_last_sync = time();
                $calendar->save();

                // Update last sync timestamp
                $calendar->google_last_sync = time();
                $calendar->save();

                if ($syncCount > 0) {
                    $this->logger->info("Synced $syncCount events to Google for '{$calendar->title}'");
                }

            } catch (\Exception $e) {
                $this->logger->error("Google Calendar sync error for '{$calendar->title}': {$e->getMessage()}");
            }
        }
    }
}
