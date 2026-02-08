<?php

namespace App\EventListener;

use App\Service\GoogleCalendarService;
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
     * Run on hourly cron
     */
    #[AsHook('hourly')]
    public function onHourly(): void
    {
        $this->syncCalendars();
    }

    /**
     * Sync all calendars with Google Calendar sync enabled
     */
    private function syncCalendars(): void
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

                // Sync FROM Google
                if ($direction === 'from_google' || $direction === 'bidirectional') {
                    $count = $this->googleService->syncFromGoogle($calendar, $calendar->google_calendar_id);
                    $syncCount += $count;
                }

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

                if ($syncCount > 0) {
                    $this->logger->info("Calendar '{$calendar->title}' synced: $syncCount events");
                }

            } catch (\Exception $e) {
                $this->logger->error("Google Calendar sync error for '{$calendar->title}': {$e->getMessage()}");
            }
        }
    }
}
