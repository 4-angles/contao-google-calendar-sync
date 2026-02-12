<?php

namespace FourAngles\ContaoGoogleCalendarBundle\EventListener;

use FourAngles\ContaoGoogleCalendarBundle\Service\GoogleCalendarService;
use Contao\CalendarModel;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Contao\CoreBundle\Framework\ContaoFramework;
use Psr\Log\LoggerInterface;

/**
 * Cron job for automatic Google Calendar sync
 *
 * Import runs every minute (pulls events from Google into Contao).
 * Export runs hourly (pushes Contao events to Google).
 * Each direction only runs for calendars that have the corresponding
 * Google Calendar ID configured (import / export).
 */
class GoogleCalendarCronListener
{
    private GoogleCalendarService $googleService;
    private LoggerInterface $logger;
    private ContaoFramework $framework;

    public function __construct(GoogleCalendarService $googleService, LoggerInterface $logger, ContaoFramework $framework)
    {
        $this->googleService = $googleService;
        $this->logger = $logger;
        $this->framework = $framework;
    }

    /**
     * Run every minute – import events from Google
     */
    #[AsCronJob('* * * * *')]
    public function onMinutely(): void
    {
        $this->importFromGoogle();
    }

    /**
     * Run on hourly cron – export events to Google
     */
    #[AsCronJob('* * * * *')]
    public function onHourly(): void
    {
        $this->exportToGoogle();
    }

    /**
     * Import events from Google Calendar for all enabled calendars
     * that have an import calendar ID configured.
     */
    private function importFromGoogle(): void
    {
        $this->framework->initialize();
        
        $calendars = CalendarModel::findBy('google_sync_enabled', '1');

        if (!$calendars) {
            return;
        }

        foreach ($calendars as $calendar) {
            if (!$calendar->google_calendar_id_import) {
                continue;
            }

            try {
                $syncCount = $this->googleService->syncFromGoogle(
                    $calendar,
                    $calendar->google_calendar_id_import
                );

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
     * Export events to Google Calendar for all enabled calendars
     * that have an export calendar ID configured.
     *
     * Delegates entirely to GoogleCalendarService::exportToGoogle()
     * which handles unpublished-event cleanup, expired-recurring cleanup,
     * skip-if-unchanged logic, and sync-loop prevention.
     */
    private function exportToGoogle(): void
    {
        $this->framework->initialize();
        
        $calendars = CalendarModel::findBy('google_sync_enabled', '1');

        if (!$calendars) {
            return;
        }

        foreach ($calendars as $calendar) {
            if (!$calendar->google_calendar_id_export) {
                continue;
            }

            try {
                $syncCount = $this->googleService->exportToGoogle(
                    $calendar,
                    $calendar->google_calendar_id_export
                );

                $calendar->google_last_sync = time();
                $calendar->save();

                if ($syncCount > 0) {
                    $this->logger->info("Exported $syncCount events to Google for '{$calendar->title}'");
                }
            } catch (\Exception $e) {
                $this->logger->error("Google Calendar export error for '{$calendar->title}': {$e->getMessage()}");
            }
        }
    }
}
