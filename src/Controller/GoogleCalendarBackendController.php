<?php

namespace FourAngles\ContaoGoogleCalendarBundle\Controller;

use FourAngles\ContaoGoogleCalendarBundle\Service\GoogleCalendarService;
use Contao\CalendarModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Message;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Backend controller for Google Calendar operations
 */
#[Route('/contao', defaults: ['_scope' => 'backend'])]
class GoogleCalendarBackendController extends AbstractController
{
    private GoogleCalendarService $googleService;
    private LoggerInterface $logger;
    private ContaoFramework $framework;

    public function __construct(
        GoogleCalendarService $googleService, 
        LoggerInterface $logger,
        ContaoFramework $framework
    ) {
        $this->googleService = $googleService;
        $this->logger = $logger;
        $this->framework = $framework;
    }

    /**
     * Import events from Google Calendar action (triggered from tl_calendar_events)
     */
    #[Route('/google-calendar-import-events', name: 'google_calendar_import_events')]
    public function importEventsAction(Request $request): Response
    {
        $this->framework->initialize();
        
        $calendarId = $request->query->get('id');
        $calendar = CalendarModel::findByPk($calendarId);
        

        if (!$calendar) {
            Message::addError('Calendar not found');
            $this->logger->error('Import failed: Calendar not found with ID ' . $calendarId);
            return new RedirectResponse('/contao?do=calendar&table=tl_calendar_events&id=' . $calendarId);
        }

        if (!$calendar->google_sync_enabled || !$calendar->google_calendar_id_import) {
            Message::addError('Google Calendar sync is not enabled for this calendar or no import calendar configured');
            $this->logger->warning('Import skipped: Calendar ' . $calendar->id . ' does not have sync enabled or import calendar ID set');
            return new RedirectResponse('/contao?do=calendar&table=tl_calendar_events&id=' . $calendarId);
        }

        try {
            $this->logger->info('Starting import from Google Calendar for calendar ' . $calendar->id, [
                'google_calendar_id' => $calendar->google_calendar_id_import
            ]);

            // Check if Google Calendar service is available
            if (!$this->googleService->getService()) {
                Message::addError('Google Calendar API is not configured or authenticated. Please check your settings.');
                $this->logger->error('Google Calendar service not available');
                return new RedirectResponse('/contao?do=calendar&table=tl_calendar_events&id=' . $calendarId);
            }

            // Import from Google to Contao
            $count = $this->googleService->syncFromGoogle($calendar, $calendar->google_calendar_id_import);
            
            // Update last sync timestamp
            $calendar->google_last_sync = time();
            $calendar->save();

            if ($count > 0) {
                Message::addConfirmation("Successfully imported $count event(s) from Google Calendar");
                $this->logger->info("Imported $count events from Google Calendar");
            } else {
                Message::addInfo("No new events to import from Google Calendar");
                $this->logger->info("No new events imported from Google Calendar");
            }
        } catch (\Exception $e) {
            Message::addError('Import failed: ' . $e->getMessage());
            $this->logger->error('Google Calendar import error: ' . $e->getMessage(), [
                'exception' => $e,
                'calendar_id' => $calendar->id,
                'trace' => $e->getTraceAsString()
            ]);
        }

        return new RedirectResponse('/contao?do=calendar&table=tl_calendar_events&id=' . $calendarId);
    }

    /**
     * Export events to Google Calendar action (triggered from tl_calendar_events)
     */
    #[Route('/google-calendar-export-events', name: 'google_calendar_export_events')]
    public function exportEventsAction(Request $request): Response
    {
        $this->framework->initialize();
        
        $calendarId = $request->query->get('id');
        $calendar = CalendarModel::findByPk($calendarId);

        if (!$calendar) {
            Message::addError('Calendar not found');
            $this->logger->error('Export failed: Calendar not found with ID ' . $calendarId);
            return new RedirectResponse('/contao?do=calendar&table=tl_calendar_events&id=' . $calendarId);
        }

        if (!$calendar->google_sync_enabled || !$calendar->google_calendar_id_export) {
            Message::addError('Google Calendar sync is not enabled for this calendar or no export calendar configured');
            $this->logger->warning('Export skipped: Calendar ' . $calendar->id . ' does not have sync enabled or export calendar ID set');
            return new RedirectResponse('/contao?do=calendar&table=tl_calendar_events&id=' . $calendarId);
        }

        try {
            $this->logger->info('Starting export to Google Calendar for calendar ' . $calendar->id, [
                'google_calendar_id' => $calendar->google_calendar_id_export
            ]);

            // Check if Google Calendar service is available
            if (!$this->googleService->getService()) {
                Message::addError('Google Calendar API is not configured or authenticated. Please check your settings.');
                $this->logger->error('Google Calendar service not available');
                return new RedirectResponse('/contao?do=calendar&table=tl_calendar_events&id=' . $calendarId);
            }

            // Export from Contao to Google
            $count = $this->googleService->exportToGoogle($calendar, $calendar->google_calendar_id_export);
            
            // Update last sync timestamp
            $calendar->google_last_sync = time();
            $calendar->save();

            if ($count > 0) {
                Message::addConfirmation("Successfully exported $count event(s) to Google Calendar");
                $this->logger->info("Exported $count events to Google Calendar");
            } else {
                Message::addInfo("No events to export to Google Calendar");
                $this->logger->info("No events exported to Google Calendar");
            }
        } catch (\Exception $e) {
            Message::addError('Export failed: ' . $e->getMessage());
            $this->logger->error('Google Calendar export error: ' . $e->getMessage(), [
                'exception' => $e,
                'calendar_id' => $calendar->id,
                'trace' => $e->getTraceAsString()
            ]);
        }

        return new RedirectResponse('/contao?do=calendar&table=tl_calendar_events&id=' . $calendarId);
    }

    /**
     * Manual sync action
     */
    #[Route('/google-calendar-sync', name: 'google_calendar_sync')]
    public function syncAction(Request $request): Response
    {
        $this->framework->initialize();
        
        $calendarId = $request->query->get('id');
        $calendar = CalendarModel::findByPk($calendarId);

        if (!$calendar) {
            Message::addError('Calendar not found');
            $this->logger->error('Sync failed: Calendar not found with ID ' . $calendarId);
            return new RedirectResponse('/contao?do=calendar');
        }

        if (!$calendar->google_sync_enabled || (!$calendar->google_calendar_id_import && !$calendar->google_calendar_id_export)) {
            Message::addError('Google Calendar sync is not enabled for this calendar');
            $this->logger->warning('Sync skipped: Calendar ' . $calendar->id . ' does not have sync enabled or calendar IDs set');
            return new RedirectResponse('/contao?do=calendar');
        }

        try {
            $syncCount = 0;
            
            $this->logger->info('Starting sync for calendar ' . $calendar->id);

            if ($calendar->google_calendar_id_import) {
                // Sync from Google to Contao
                $count = $this->googleService->syncFromGoogle($calendar, $calendar->google_calendar_id_import);
                $syncCount += $count;
                Message::addInfo("Synced $count events from Google Calendar");
                $this->logger->info("Synced $count events from Google Calendar");
            }

            if ($calendar->google_calendar_id_export) {
                // Sync all events from this calendar to Google
                $events = \Contao\CalendarEventsModel::findBy('pid', $calendar->id);
                
                if ($events) {
                    $toGoogleCount = 0;
                    foreach ($events as $event) {
                        // Skip unpublished events
                        if (!$event->published) {
                            continue;
                        }
                        
                        $googleEventId = $this->googleService->syncEventToGoogle(
                            $event,
                            $calendar->google_calendar_id_export
                        );
                        
                        if ($googleEventId) {
                            $event->google_event_id = $googleEventId;
                            $event->google_updated = time();
                            $event->save();
                            $toGoogleCount++;
                        }
                    }
                    
                    Message::addInfo("Synced $toGoogleCount events to Google Calendar");
                    $this->logger->info("Synced $toGoogleCount events to Google Calendar");
                    $syncCount += $toGoogleCount;
                } else {
                    Message::addInfo("No events found to sync to Google Calendar");
                }
            }

            // Update last sync timestamp
            $calendar->google_last_sync = time();
            $calendar->save();

            Message::addConfirmation("Calendar synced successfully! Total: $syncCount events");
            $this->logger->info('Calendar sync completed successfully for calendar ' . $calendar->id);
        } catch (\Exception $e) {
            Message::addError('Sync failed: ' . $e->getMessage());
            $this->logger->error('Google Calendar sync error: ' . $e->getMessage(), [
                'exception' => $e,
                'calendar_id' => $calendar->id,
            ]);
        }

        return new RedirectResponse('/contao?do=calendar');
    }

    /**
     * Sync all calendars action
     */
    #[Route('/google-calendar-sync-all', name: 'google_calendar_sync_all')]
    public function syncAllAction(Request $request): Response
    {
        $this->framework->initialize();
        
        $this->logger->info('Starting sync all calendars');
        
        // Find all calendars with sync enabled
        $calendars = CalendarModel::findBy('google_sync_enabled', '1');

        if (!$calendars) {
            Message::addInfo('No calendars with Google sync enabled');
            $this->logger->info('Sync all: No calendars with sync enabled found');
            return new RedirectResponse('/contao?do=calendar');
        }

        $totalSynced = 0;
        $successCount = 0;
        $errorCount = 0;

        foreach ($calendars as $calendar) {
            if (!$calendar->google_calendar_id_import && !$calendar->google_calendar_id_export) {
                $this->logger->warning('Skipping calendar ' . $calendar->id . ': No Google Calendar IDs set');
                continue;
            }

            try {
                $this->logger->info('Syncing calendar ' . $calendar->id . ' (' . $calendar->title . ')');
                
                if ($calendar->google_calendar_id_import) {
                    $count = $this->googleService->syncFromGoogle($calendar, $calendar->google_calendar_id_import);
                    $totalSynced += $count;
                    $this->logger->info("Synced $count events from Google for calendar " . $calendar->id);
                }

                if ($calendar->google_calendar_id_export) {
                    $events = \Contao\CalendarEventsModel::findBy('pid', $calendar->id);
                    
                    if ($events) {
                        foreach ($events as $event) {
                            $googleEventId = $this->googleService->syncEventToGoogle(
                                $event,
                                $calendar->google_calendar_id_export
                            );
                            
                            if ($googleEventId) {
                                $event->google_event_id = $googleEventId;
                                $event->google_updated = time();
                                $event->save();
                                $totalSynced++;
                            }
                        }
                    }
                }

                $calendar->google_last_sync = time();
                $calendar->save();
                $successCount++;
                $this->logger->info('Successfully synced calendar ' . $calendar->id);
            } catch (\Exception $e) {
                $errorCount++;
                $this->logger->error('Google Calendar sync error for calendar ' . $calendar->id . ': ' . $e->getMessage(), [
                    'exception' => $e,
                    'calendar_id' => $calendar->id,
                ]);
            }
        }

        if ($successCount > 0) {
            Message::addConfirmation("Successfully synced $successCount calendar(s) with $totalSynced event(s)");
        }
        if ($errorCount > 0) {
            Message::addError("Failed to sync $errorCount calendar(s). Check logs for details.");
        }
        
        $this->logger->info("Sync all completed: $successCount success, $errorCount errors, $totalSynced total events");

        return new RedirectResponse('/contao?do=calendar');
    }

    /**
     * OAuth callback handler
     */
    #[Route('/google-calendar-callback', name: 'google_calendar_callback')]
    public function oauthCallbackAction(Request $request): Response
    {
        $this->framework->initialize();
        
        $code = $request->query->get('code');
        
        if (!$code) {
            Message::addError('No authorization code received');
            return new RedirectResponse('/contao');
        }

        if ($this->googleService->authenticate($code)) {
            Message::addConfirmation('Successfully connected to Google Calendar');
        } else {
            Message::addError('Failed to authenticate with Google Calendar');
        }

        return new RedirectResponse('/contao?do=calendar');
    }

    /**
     * Generate auth URL
     */
    #[Route('/google-calendar-auth', name: 'google_calendar_auth')]
    public function authAction(): Response
    {
        $this->framework->initialize();
        
        $authUrl = $this->googleService->getAuthUrl();
        
        if ($authUrl) {
            return new RedirectResponse($authUrl);
        }

        Message::addError('Could not generate Google Calendar authorization URL. Check your GOOGLE_CALENDAR_* environment variables.');
        return new RedirectResponse('/contao?do=calendar');
    }
}
