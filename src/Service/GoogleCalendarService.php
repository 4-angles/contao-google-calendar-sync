<?php

namespace FourAngles\ContaoGoogleCalendarBundle\Service;

use Contao\CalendarEventsModel;
use Contao\CalendarModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Database;
use Contao\StringUtil;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Psr\Log\LoggerInterface;

class GoogleCalendarService
{
    private ?Client $client = null;
    private ?Calendar $service = null;
    private string $credentialsPath;
    private LoggerInterface $logger;
    private ContaoFramework $framework;
    private int $lastApiCall = 0;
    private int $minApiDelay = 100000; // 100ms in microseconds (Google allows 10 req/sec)
    private int $maxRetries = 3;
    private int $apiCallCount = 0;
    private int $currentMinute = 0;
    private int $maxCallsPerMinute = 590; // Stay under 600/minute limit

    public function __construct(string $projectDir, LoggerInterface $logger, ContaoFramework $framework)
    {
        $this->credentialsPath = $projectDir . '/var/google-calendar-credentials.json';
        $this->logger = $logger;
        $this->framework = $framework;
    }

    /**
     * Initialize Google Client
     */
    public function getClient(): ?Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $enabled = $_ENV['GOOGLE_CALENDAR_ENABLED'] ?? false;
        // Handle string "true"/"false" from .env files
        if (is_string($enabled)) {
            $enabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
        }
        if (!$enabled) {
            $this->logger->warning('Google Calendar is not enabled in environment configuration (set GOOGLE_CALENDAR_ENABLED=true)');
            return null;
        }

        try {
            $this->client = new Client();
            $this->client->setApplicationName($_ENV['GOOGLE_CALENDAR_APPLICATION_NAME'] ?? 'Contao Calendar Sync');
            $this->client->setScopes(Calendar::CALENDAR);
            
            $clientId = $_ENV['GOOGLE_CALENDAR_CLIENT_ID'] ?? '';
            $clientSecret = $_ENV['GOOGLE_CALENDAR_CLIENT_SECRET'] ?? '';
            $redirectUri = $_ENV['GOOGLE_CALENDAR_REDIRECT_URI'] ?? '';
            
            if (empty($clientId) || empty($clientSecret)) {
                $this->logger->error('Google Calendar credentials are not configured');
                return null;
            }
            
            $this->client->setAuthConfig([
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uris' => [$redirectUri],
            ]);
            $this->client->setRedirectUri($redirectUri);
            $this->client->setAccessType('offline');
            $this->client->setPrompt('select_account consent');

            // Load previously authorized credentials from file
            if (file_exists($this->credentialsPath)) {
                $accessToken = json_decode(file_get_contents($this->credentialsPath), true);
                $this->client->setAccessToken($accessToken);
                
                // Refresh token if expired
                if ($this->client->isAccessTokenExpired()) {
                    if ($this->client->getRefreshToken()) {
                        $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
                        $this->saveCredentials($this->client->getAccessToken());
                    } else {
                        $this->logger->warning('Google Calendar access token expired and no refresh token available - re-authentication required');
                        // Don't return null - still return client so user can re-authenticate
                    }
                }
            } else {
                $this->logger->info('Google Calendar credentials file not found - authentication required', [
                    'path' => $this->credentialsPath
                ]);
                // Don't return null - still return client so user can authenticate
            }

            return $this->client;
        } catch (\Exception $e) {
            $this->logger->error('Google Calendar API Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Get Google Calendar Service
     */
    public function getService(): ?Calendar
    {
        if ($this->service !== null) {
            return $this->service;
        }

        $client = $this->getClient();
        if ($client === null) {
            return null;
        }

        $this->service = new Calendar($client);
        return $this->service;
    }

    /**
     * Save credentials to file
     */
    public function saveCredentials(array $accessToken): void
    {
        if (!is_dir(dirname($this->credentialsPath))) {
            mkdir(dirname($this->credentialsPath), 0755, true);
        }
        file_put_contents($this->credentialsPath, json_encode($accessToken));
    }

    /**
     * Exchange authorization code for access token
     */
    public function authenticate(string $code): bool
    {
        try {
            $client = $this->getClient();
            if ($client === null) {
                return false;
            }

            $accessToken = $client->fetchAccessTokenWithAuthCode($code);
            
            if (isset($accessToken['error'])) {
                throw new \Exception($accessToken['error']);
            }

            $this->saveCredentials($accessToken);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Google Calendar Authentication Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get authorization URL
     */
    public function getAuthUrl(): ?string
    {
        $client = $this->getClient();
        if ($client === null) {
            return null;
        }

        return $client->createAuthUrl();
    }

    /**
     * Sync Contao event to Google Calendar
     */
    public function syncEventToGoogle(CalendarEventsModel $event, string $googleCalendarId, ?string $existingEventId = null): ?string
    {
        // Skip unpublished events
        if (!$event->published) {
            $this->logger->debug('Skipping unpublished event', [
                'event_id' => $event->id,
                'event_title' => $event->title
            ]);
            return null;
        }
        
        // Skip events imported from Google only if exporting to the same calendar they were imported from
        // This prevents sync loops when using the same calendar for both import and export
        if ($event->google_event_origin === 'google' && $event->google_calendar_source) {
            // Only skip if exporting to the same calendar it was imported from
            if ($event->google_calendar_source === $googleCalendarId) {
                $this->logger->debug('Skipping event imported from same Google Calendar (would create sync loop)', [
                    'event_id' => $event->id,
                    'event_title' => $event->title,
                    'source_calendar' => $event->google_calendar_source,
                    'export_calendar' => $googleCalendarId
                ]);
                return null;
            }
        }
        
        // Skip recurring events that have ended
        if ($event->recurring && $event->repeatEnd > 0 && $event->repeatEnd < time()) {
            $this->logger->debug('Skipping recurring event that has ended', [
                'event_id' => $event->id,
                'event_title' => $event->title,
                'repeat_end' => date('Y-m-d H:i:s', $event->repeatEnd)
            ]);
            return null;
        }
        
        // Skip events beyond the configured sync date
        $calendar = CalendarModel::findByPk($event->pid);
        $syncUntil = ($calendar && $calendar->google_sync_until) ? (int)$calendar->google_sync_until : strtotime('+1 year');
        $eventStartDate = $event->startDate ?? $event->startTime ?? 0;
        if ($eventStartDate > $syncUntil) {
            $this->logger->debug('Skipping event beyond sync date', [
                'event_id' => $event->id,
                'event_title' => $event->title,
                'start_date' => date('Y-m-d', $eventStartDate),
                'sync_until' => date('Y-m-d', $syncUntil)
            ]);
            return null;
        }
        
        $service = $this->getService();
        if ($service === null) {
            $this->logger->error('Cannot sync event: Google Calendar service not available');
            return null;
        }

        $googleEvent = $this->convertContaoEventToGoogle($event);
        
        $this->logger->debug('Syncing event to Google', [
            'event_id' => $event->id,
            'event_title' => $event->title,
            'google_calendar_id' => $googleCalendarId,
            'has_google_event_id' => !empty($event->google_event_id),
        ]);
        
        // Retry logic with exponential backoff
        $retries = 0;
        $hadStaleId = false; // Track if we've already cleared a stale ID
        while ($retries <= $this->maxRetries) {
            try {
                // Rate limiting: ensure minimum delay between API calls
                $this->throttle();
                
                // Only use the provided existing ID - don't fall back to google_event_id
                // because that's the IMPORT ID which exists in a DIFFERENT calendar
                $googleEventIdToUse = $hadStaleId ? null : $existingEventId;
                
                // Check if event already has a Google Calendar ID
                if ($googleEventIdToUse) {
                    // Update existing event
                    $updatedEvent = $service->events->update(
                        $googleCalendarId,
                        $googleEventIdToUse,
                        $googleEvent
                    );
                    $this->logger->info('Updated Google Calendar event', [
                        'event_id' => $event->id,
                        'google_event_id' => $updatedEvent->getId(),
                    ]);
                    return $updatedEvent->getId();
                } else {
                    // Create new event
                    $createdEvent = $service->events->insert($googleCalendarId, $googleEvent);
                    $this->logger->info('Created new Google Calendar event', [
                        'event_id' => $event->id,
                        'google_event_id' => $createdEvent->getId(),
                    ]);
                    return $createdEvent->getId();
                }
            } catch (\Google\Service\Exception $e) {
                // Check if event was deleted from Google Calendar (404)
                if ($e->getCode() === 404 && $googleEventIdToUse) {
                    $staleGoogleEventId = $googleEventIdToUse;
                    
                    $this->logger->warning('Event not found in Google Calendar, clearing stale ID and recreating', [
                        'event_id' => $event->id,
                        'event_title' => $event->title,
                        'old_google_event_id' => $staleGoogleEventId
                    ]);
                    
                    // Clear the stale ID from the event model if it matches
                    if ($event->google_event_id === $staleGoogleEventId) {
                        $event->google_event_id = '';
                        Database::getInstance()
                            ->prepare('UPDATE tl_calendar_events SET google_event_id = ? WHERE id = ?')
                            ->execute('', $event->id);
                    }
                    
                    // Clear the stale export ID if it matches
                    if ($event->google_export_event_id === $staleGoogleEventId) {
                        $event->google_export_event_id = '';
                        Database::getInstance()
                            ->prepare('UPDATE tl_calendar_events SET google_export_event_id = ? WHERE id = ?')
                            ->execute('', $event->id);
                    }
                    
                    // Mark that we've cleared a stale ID - next iteration will create new event
                    $hadStaleId = true;
                    
                    // Reset retries and try once more immediately to create new event
                    $retries = 0;
                    continue;
                }
                // Check if it's a rate limit error
                if ($e->getCode() === 403 && strpos($e->getMessage(), 'rateLimitExceeded') !== false) {
                    // Wait until next minute boundary
                    $currentSecond = (int)date('s');
                    $waitSeconds = 60 - $currentSecond;
                    $this->logger->warning('Rate limit hit, waiting until next minute', [
                        'event_id' => $event->id,
                        'wait_seconds' => $waitSeconds
                    ]);
                    sleep($waitSeconds + 1); // Wait for next minute + 1 second buffer
                    
                    // Reset per-minute counter
                    $this->apiCallCount = 0;
                    $this->currentMinute = (int)date('i');
                    
                    // Retry without incrementing retry counter (rate limit is not a failure)
                    continue;
                }
                // Log and return null for other errors or max retries exceeded
                $this->logger->error('Error syncing event to Google Calendar', [
                    'event_id' => $event->id,
                    'event_title' => $event->title,
                    'error' => $e->getMessage(),
                ]);
                return null;
            } catch (\Exception $e) {
                // Handle other exceptions
                $this->logger->error('Error syncing event to Google Calendar', [
                    'event_id' => $event->id,
                    'event_title' => $event->title,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        }
        
        // If we get here, all retries failed
        $this->logger->error('Error syncing event to Google Calendar - max retries exceeded', [
            'event_id' => $event->id,
            'event_title' => $event->title,
        ]);
        return null;
    }

    /**
     * Export all Contao events from a calendar to Google Calendar
     */
    public function exportToGoogle(CalendarModel $calendar, string $googleCalendarId): int
    {
        $this->logger->info('Starting export to Google Calendar', [
            'calendar_id' => $calendar->id,
            'google_calendar_id' => $googleCalendarId
        ]);

        $service = $this->getService();
        if ($service === null) {
            $this->logger->error('Cannot export: Google Calendar service not available');
            return 0;
        }

        // Get all published events from the Contao calendar
        $events = CalendarEventsModel::findBy(
            ['pid=?', 'published=?'],
            [$calendar->id, 1],
            ['order' => 'startDate ASC']
        );

        if (!$events) {
            $this->logger->info('No published events found for export', [
                'calendar_id' => $calendar->id
            ]);
            return 0;
        }

        $syncCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        foreach ($events as $event) {
            try {
                // For export, use google_export_event_id to track the event in export calendar
                $existingExportId = $event->google_export_event_id ?: null;
                
                // Skip events that haven't been modified since last export
                if ($existingExportId && $event->google_updated && $event->tstamp <= $event->google_updated) {
                    $skippedCount++;
                    continue;
                }
                
                // Sync event to Google (pass existing export ID for update)
                $googleEventId = $this->syncEventToGoogle($event, $googleCalendarId, $existingExportId);
                
                if ($googleEventId) {
                    // Store the export calendar's event ID separately
                    $event->google_export_event_id = $googleEventId;
                    $event->google_updated = time();
                    // Only set origin to 'contao' if it wasn't imported from Google
                    if ($event->google_event_origin !== 'google') {
                        $event->google_event_origin = 'contao';
                    }
                    $event->save();
                    
                    $syncCount++;
                    $this->logger->debug('Exported event to Google Calendar', [
                        'event_id' => $event->id,
                        'google_export_event_id' => $googleEventId
                    ]);
                }
            } catch (\Exception $e) {
                $errorCount++;
                $this->logger->error('Error exporting event to Google Calendar', [
                    'event_id' => $event->id,
                    'event_title' => $event->title,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->logger->info('Completed export to Google Calendar', [
            'calendar_id' => $calendar->id,
            'exported_count' => $syncCount,
            'skipped_unchanged' => $skippedCount,
            'error_count' => $errorCount
        ]);

        return $syncCount;
    }

    /**
     * Delete event from Google Calendar
     */
    public function deleteEventFromGoogle(string $googleEventId, string $googleCalendarId): bool
    {
        $service = $this->getService();
        if ($service === null) {
            return false;
        }

        $retries = 0;
        while ($retries <= $this->maxRetries) {
            try {
                // Rate limiting
                $this->throttle();
                
                $service->events->delete($googleCalendarId, $googleEventId);
                return true;
            } catch (\Google\Service\Exception $e) {
                // Check if it's a rate limit error
                if ($e->getCode() === 403 && strpos($e->getMessage(), 'rateLimitExceeded') !== false) {
                    $retries++;
                    if ($retries <= $this->maxRetries) {
                        $waitTime = pow(2, $retries) * 1000000; // Exponential backoff
                        $this->logger->warning('Rate limit hit on delete, retrying', [
                            'google_event_id' => $googleEventId,
                            'retry' => $retries,
                            'wait_ms' => $waitTime / 1000
                        ]);
                        usleep($waitTime);
                        continue;
                    }
                }
                $this->logger->error('Error deleting event from Google Calendar: ' . $e->getMessage());
                return false;
            } catch (\Exception $e) {
                $this->logger->error('Error deleting event from Google Calendar: ' . $e->getMessage());
                return false;
            }
        }
        
        return false;
    }
    
    /**
     * Throttle API calls to respect rate limits
     */
    private function throttle(): void
    {
        $now = (int)(microtime(true) * 1000000); // Current time in microseconds
        $currentMinute = (int)date('i');
        
        // Reset counter if we're in a new minute
        if ($currentMinute !== $this->currentMinute) {
            $this->apiCallCount = 0;
            $this->currentMinute = $currentMinute;
        }
        
        // Check if we're approaching the per-minute limit
        if ($this->apiCallCount >= $this->maxCallsPerMinute) {
            $currentSecond = (int)date('s');
            $waitSeconds = 60 - $currentSecond;
            $this->logger->info('Approaching rate limit, waiting for next minute', [
                'calls_this_minute' => $this->apiCallCount,
                'wait_seconds' => $waitSeconds
            ]);
            sleep($waitSeconds + 1);
            $this->apiCallCount = 0;
            $this->currentMinute = (int)date('i');
        }
        
        // Enforce minimum delay between calls
        $timeSinceLastCall = $now - $this->lastApiCall;
        if ($timeSinceLastCall < $this->minApiDelay) {
            $sleepTime = $this->minApiDelay - $timeSinceLastCall;
            usleep($sleepTime);
        }
        
        $this->lastApiCall = (int)(microtime(true) * 1000000);
        $this->apiCallCount++;
    }

    /**
     * Convert Contao event to Google Calendar event
     */
    private function convertContaoEventToGoogle(CalendarEventsModel $event): Event
    {
        $googleEvent = new Event();
        
        // Check if event should be synced as "Busy" (privacy mode)
        // Use calendar-level setting only
        $calendar = CalendarModel::findByPk($event->pid);
        $syncAsBusy = $calendar && $calendar->google_sync_as_busy;
        
        if ($syncAsBusy) {
            // Use custom busy text or default to 'Busy'
            $busyText = ($calendar && $calendar->google_sync_busy_text) ? $calendar->google_sync_busy_text : 'Busy';
            $googleEvent->setSummary($busyText);
            $googleEvent->setDescription('');
            // No location in privacy mode
        } else {
            $googleEvent->setSummary($event->title);
            $googleEvent->setDescription(strip_tags($event->teaser ?: ''));

            // Set location if available
            if ($event->location) {
                $googleEvent->setLocation($event->location);
            }
        }

        // Use Europe/Berlin timezone for export - times are stored as absolute local times
        $timezone = 'Europe/Berlin';

        // Set start date/time (always sent)
        $start = new EventDateTime();
        if ($event->addTime) {
            // Format as local time without timezone conversion
            $start->setDateTime(date('Y-m-d\TH:i:s', $event->startTime));
            $start->setTimeZone($timezone);
        } else {
            $start->setDate(date('Y-m-d', $event->startDate));
        }
        $googleEvent->setStart($start);

        // Set end date/time (always sent)
        $end = new EventDateTime();
        if ($event->addTime) {
            $endTime = $event->endTime ?: $event->startTime;
            $end->setDateTime(date('Y-m-d\TH:i:s', $endTime));
            $end->setTimeZone($timezone);
        } else {
            $endDate = $event->endDate ?: $event->startDate;
            // For all-day events, add one day to end date as per Google Calendar spec
            $end->setDate(date('Y-m-d', strtotime('+1 day', $endDate)));
        }
        $googleEvent->setEnd($end);

        // Handle recurring events
        if ($event->recurring) {
            $rrule = $this->buildRRule($event);
            if ($rrule) {
                $googleEvent->setRecurrence([$rrule]);
            }
        }

        return $googleEvent;
    }

    /**
     * Build RRULE string from Contao recurring event settings
     */
    private function buildRRule(CalendarEventsModel $event): ?string
    {
        if (!$event->recurring) {
            return null;
        }

        // Deserialize repeatEach (contains interval and unit)
        $repeatEach = StringUtil::deserialize($event->repeatEach, true);
        if (empty($repeatEach)) {
            return null;
        }

        $interval = (int)($repeatEach['value'] ?? 1);
        $unit = $repeatEach['unit'] ?? 'days';

        // Map Contao units to RRULE FREQ
        $freqMap = [
            'days' => 'DAILY',
            'weeks' => 'WEEKLY',
            'months' => 'MONTHLY',
            'years' => 'YEARLY',
        ];

        $freq = $freqMap[$unit] ?? null;
        if (!$freq) {
            return null;
        }

        $rrule = 'RRULE:FREQ=' . $freq;

        // Add interval if greater than 1
        if ($interval > 1) {
            $rrule .= ';INTERVAL=' . $interval;
        }

        // For weekly events, add BYDAY based on start date's day of week
        // This ensures the event repeats on the same day(s) of the week
        if ($freq === 'WEEKLY') {
            $startDate = $event->addTime ? $event->startTime : $event->startDate;
            $dayOfWeek = strtoupper(substr(date('D', $startDate), 0, 2));
            $rrule .= ';BYDAY=' . $dayOfWeek;
        }

        // Add COUNT if recurrences is set
        if ($event->recurrences > 0) {
            $rrule .= ';COUNT=' . $event->recurrences;
        }
        // Otherwise add UNTIL if repeatEnd is set
        elseif ($event->repeatEnd > 0) {
            // Google Calendar expects UNTIL in UTC format: YYYYMMDDTHHMMSSZ
            $until = gmdate('Ymd\\THis\\Z', $event->repeatEnd);
            $rrule .= ';UNTIL=' . $until;
        }

        return $rrule;
    }

    /**
     * Get list of user's Google Calendars
     */
    public function getCalendarList(): array
    {
        $service = $this->getService();
        if ($service === null) {
            return [];
        }

        try {
            // Rate limiting
            $this->throttle();
            $calendarList = $service->calendarList->listCalendarList();
            $calendars = [];
            
            foreach ($calendarList->getItems() as $calendarListEntry) {
                $calendars[] = [
                    'id' => $calendarListEntry->getId(),
                    'summary' => $calendarListEntry->getSummary(),
                    'description' => $calendarListEntry->getDescription(),
                    'primary' => $calendarListEntry->getPrimary(),
                ];
            }
            
            return $calendars;
        } catch (\Exception $e) {
            $this->logger->error('Error fetching Google Calendar list: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Sync Google Calendar events to Contao
     * Note: Only syncs events from today up to 1 year ahead
     * Past events are preserved and not deleted during sync
     */
    public function syncFromGoogle(CalendarModel $calendar, string $googleCalendarId): int
    {
        // Initialize Contao framework for model operations
        $this->framework->initialize();
        
        $service = $this->getService();

        if ($service === null) {
            $this->logger->error('Cannot sync from Google: Service is null', [
                'calendar_id' => $calendar->id,
                'google_calendar_id' => $googleCalendarId
            ]);
            return 0;
        }
        
        $this->logger->info('Starting sync from Google Calendar', [
            'calendar_id' => $calendar->id,
            'google_calendar_id' => $googleCalendarId
        ]);

        try {
            $syncCount = 0;
            $pageToken = null;
            $allGoogleEventIds = [];
            $processedBaseIds = [];
            
            // Get configurable sync date (default 1 year ahead)
            $syncUntil = ($calendar->google_sync_until) ? (int)$calendar->google_sync_until : strtotime('+1 year');

            do {
                // Sync only from today to configured date
                // Use singleEvents=true to expand recurring events into individual instances
                // This is required for orderBy=startTime and timeMin/timeMax filtering
                // Use showDeleted=true to get cancelled/deleted events so we can detect deletions
                $optParams = [
                    'maxResults' => 250,
                    'orderBy' => 'startTime',
                    'singleEvents' => true, // Expand recurring events into instances
                    'showDeleted' => true, // Include deleted events so we can detect deletions
                    'timeMin' => date('c'), // From today
                    'timeMax' => date('c', $syncUntil), // Up to configured date
                ];

                if ($pageToken) {
                    $optParams['pageToken'] = $pageToken;
                }
                
                // Rate limiting
                $this->throttle();
                
                $this->logger->info('Fetching events from Google Calendar', [
                    'calendar_id' => $googleCalendarId,
                    'params' => $optParams
                ]);
                
                $events = $service->events->listEvents($googleCalendarId, $optParams);
                
                $itemCount = $events->getItems() ? count($events->getItems()) : 0;
                
                $this->logger->info('Retrieved events from Google', [
                    'count' => $itemCount,
                    'has_next_page' => $events->getNextPageToken() ? 'yes' : 'no'
                ]);
                
                if ($itemCount === 0) {
                    $this->logger->warning('No events found in Google Calendar for the specified time range', [
                        'timeMin' => $optParams['timeMin'],
                        'timeMax' => $optParams['timeMax']
                    ]);
                }

                foreach ($events->getItems() as $googleEvent) {
                    $googleEventId = $googleEvent->getId();
                    
                    // With singleEvents=true, recurring event instances have IDs like "baseId_20260301T100000Z"
                    // Extract the base event ID for deduplication
                    $baseEventId = $googleEventId;
                    if (strpos($googleEventId, '_') !== false) {
                        $baseEventId = substr($googleEventId, 0, strpos($googleEventId, '_'));
                    }
                    
                    // Skip if we already processed an instance of this recurring event
                    if (isset($processedBaseIds[$baseEventId])) {
                        continue;
                    }
                    
                    // Skip cancelled events - don't add them to the list of existing IDs
                    // so that cleanup will delete them from Contao
                    if ($googleEvent->getStatus() === 'cancelled') {
                        $this->logger->info('Found CANCELLED event from Google - will be cleaned up', [
                            'google_event_id' => $googleEventId,
                            'base_event_id' => $baseEventId,
                            'status' => $googleEvent->getStatus()
                        ]);
                        continue;
                    }
                    
                    $allGoogleEventIds[] = $googleEventId;
                    $allGoogleEventIds[] = $baseEventId; // Also track base ID for cleanup
                    
                    // Mark this base event as processed (only import first instance of recurring events)
                    $processedBaseIds[$baseEventId] = true;
                    
                    // Check if event already exists in Contao (try both full ID and base ID)
                    $existingEvent = CalendarEventsModel::findOneBy(
                        ['google_event_id=?', 'pid=?'],
                        [$googleEventId, $calendar->id]
                    );
                    
                    if (!$existingEvent && $baseEventId !== $googleEventId) {
                        $existingEvent = CalendarEventsModel::findOneBy(
                            ['google_event_id=?', 'pid=?'],
                            [$baseEventId, $calendar->id]
                        );
                    }
                    
                    // Also check if this event was exported FROM Contao (has matching google_export_event_id)
                    // This prevents re-importing events we exported
                    if (!$existingEvent) {
                        $existingEvent = CalendarEventsModel::findOneBy(
                            ['google_export_event_id=?', 'pid=?'],
                            [$googleEventId, $calendar->id]
                        );
                        if (!$existingEvent && $baseEventId !== $googleEventId) {
                            $existingEvent = CalendarEventsModel::findOneBy(
                                ['google_export_event_id=?', 'pid=?'],
                                [$baseEventId, $calendar->id]
                            );
                        }
                        
                        // If found by export ID, this is an event we exported - skip to prevent loop
                        if ($existingEvent) {
                            $this->logger->debug('Skipping event that was exported from Contao (would create sync loop)', [
                                'event_id' => $existingEvent->id,
                                'google_export_event_id' => $existingEvent->google_export_event_id,
                                'google_event_id_from_import' => $googleEventId
                            ]);
                            continue;
                        }
                    }

                    if ($existingEvent) {
                        // Check origin - only update if it was imported from Google (not exported from Contao)
                        if ($existingEvent->google_event_origin === 'contao') {
                            $this->logger->debug('Skipping event exported from Contao (would create sync loop)', [
                                'event_id' => $existingEvent->id,
                                'google_event_id' => $googleEventId
                            ]);
                            continue;
                        }
                        
                        // Event was imported from Google - check if it was updated in Google
                        $googleUpdatedTime = $googleEvent->getUpdated() ? strtotime($googleEvent->getUpdated()) : 0;
                        $contaoUpdatedTime = (int)$existingEvent->google_updated;

                        // Update if Google has a newer version, or if we don't have a timestamp yet
                        if ($googleUpdatedTime > $contaoUpdatedTime || $contaoUpdatedTime === 0) {
                            $this->updateContaoEvent($existingEvent, $googleEvent, $googleCalendarId);
                            $this->logger->info('Updated existing event from Google', [
                                'event_id' => $existingEvent->id,
                                'google_event_id' => $googleEventId
                            ]);
                            $syncCount++;
                            
                            // Auto-export to export calendar if configured
                            if ($calendar->google_calendar_id_export && $calendar->google_calendar_id_export !== $googleCalendarId) {
                                $this->logger->info('Triggering auto-export for updated event', [
                                    'event_id' => $existingEvent->id,
                                    'published' => $existingEvent->published,
                                    'export_calendar' => $calendar->google_calendar_id_export
                                ]);
                                $this->autoExportEvent($existingEvent, $calendar->google_calendar_id_export);
                            }
                        }
                    } else {
                        // Create new event in Contao
                        // If this is a recurring event instance, fetch the master event for recurrence info
                        $masterEvent = null;
                        $recurringEventId = $googleEvent->getRecurringEventId();
                        if ($recurringEventId) {
                            try {
                                $this->throttle();
                                $masterEvent = $service->events->get($googleCalendarId, $recurringEventId);
                            } catch (\Exception $e) {
                                $this->logger->warning('Could not fetch master recurring event', [
                                    'recurring_event_id' => $recurringEventId,
                                    'error' => $e->getMessage()
                                ]);
                            }
                        }
                        
                        try {
                            $insertId = $this->createContaoEvent($calendar, $googleEvent, $googleCalendarId, $masterEvent);
                            $this->logger->info('Created new event from Google', [
                                'event_id' => $insertId,
                                'google_event_id' => $googleEventId,
                                'summary' => $googleEvent->getSummary()
                            ]);
                            $syncCount++;
                            
                            // Auto-export to export calendar if configured
                            if ($calendar->google_calendar_id_export && $calendar->google_calendar_id_export !== $googleCalendarId) {
                                $newEvent = CalendarEventsModel::findByPk($insertId);
                                if ($newEvent) {
                                    $this->logger->info('Triggering auto-export for new imported event', [
                                        'event_id' => $insertId,
                                        'event_title' => $newEvent->title,
                                        'published' => $newEvent->published,
                                        'export_calendar' => $calendar->google_calendar_id_export
                                    ]);
                                    $this->autoExportEvent($newEvent, $calendar->google_calendar_id_export);
                                } else {
                                    $this->logger->error('Could not reload event for auto-export', [
                                        'insert_id' => $insertId
                                    ]);
                                }
                            } else {
                                $this->logger->info('Skipping auto-export', [
                                    'event_id' => $insertId,
                                    'export_calendar_configured' => !empty($calendar->google_calendar_id_export) ? 'yes' : 'no',
                                    'same_as_import' => ($calendar->google_calendar_id_export === $googleCalendarId) ? 'yes' : 'no'
                                ]);
                            }
                        } catch (\Exception $e) {
                            $this->logger->error('Failed to create event from Google', [
                                'google_event_id' => $googleEventId,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                }
                
                $pageToken = $events->getNextPageToken();
            } while ($pageToken);
            
            // Clean up events that no longer exist in Google Calendar
            // Only remove events that were synced from Google (have google_event_id)
            // and are in the future (to avoid deleting historical events)
            $this->logger->info('About to run cleanup - collected Google event IDs', [
                'total_google_ids' => count($allGoogleEventIds),
                'unique_google_ids' => count(array_unique($allGoogleEventIds)),
                'sample_ids' => array_slice(array_unique($allGoogleEventIds), 0, 20)
            ]);
            $this->cleanupDeletedGoogleEvents($calendar, $allGoogleEventIds);
            
            $this->logger->info('Completed sync from Google Calendar', [
                'calendar_id' => $calendar->id,
                'synced_count' => $syncCount
            ]);

            return $syncCount;
        } catch (\Exception $e) {
            $this->logger->error('Error syncing from Google Calendar: ' . $e->getMessage(), [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 0;
        }
    }
    
    /**
     * Clean up Contao events that no longer exist in Google Calendar
     */
    private function cleanupDeletedGoogleEvents(CalendarModel $calendar, array $existingGoogleIds): void
    {
        try {
            // Find all Contao events for this calendar that have a google_event_id
            // and are in the future (start date >= today)
            $today = strtotime('today');
            
            $this->logger->info('Starting cleanup of deleted Google events', [
                'calendar_id' => $calendar->id,
                'google_ids_count' => count($existingGoogleIds),
                'today_timestamp' => $today,
                'today_date' => date('Y-m-d', $today)
            ]);
            
            // Query for events that have a google_event_id (not empty) and start today or later
            // Use Database for more precise control over the query
            $result = Database::getInstance()
                ->prepare("SELECT id FROM tl_calendar_events WHERE pid=? AND google_event_id!='' AND google_event_id IS NOT NULL AND startDate>=?")
                ->execute($calendar->id, $today);
            
            $eventIds = $result->fetchEach('id');
            
            if (empty($eventIds)) {
                $this->logger->info('No Contao events with google_event_id found for cleanup check');
                return;
            }
            
            $this->logger->info('Found Contao events to check against Google list', [
                'contao_events_count' => count($eventIds),
                'event_ids' => array_slice($eventIds, 0, 20),
                'google_ids_from_api' => array_slice($existingGoogleIds, 0, 30)
            ]);
            
            // Get export calendar ID if configured
            $exportCalendarId = $calendar->google_calendar_id_export ?: null;
            $service = $exportCalendarId ? $this->getService() : null;
            
            $deletedCount = 0;
            foreach ($eventIds as $eventId) {
                $event = CalendarEventsModel::findByPk($eventId);
                if (!$event) {
                    continue;
                }
                
                // If this event's Google ID is not in the list from Google, it was deleted
                $eventGoogleId = $event->google_event_id;
                
                // Also check the base ID (without instance suffix) for recurring events
                $baseEventId = $eventGoogleId;
                if (strpos($eventGoogleId, '_') !== false) {
                    $baseEventId = substr($eventGoogleId, 0, strpos($eventGoogleId, '_'));
                }
                
                $isFound = in_array($eventGoogleId, $existingGoogleIds) || in_array($baseEventId, $existingGoogleIds);
                
                $this->logger->info('Checking event for cleanup', [
                    'contao_id' => $event->id,
                    'google_event_id' => $eventGoogleId,
                    'base_event_id' => $baseEventId,
                    'title' => $event->title,
                    'start_date' => date('Y-m-d', $event->startDate),
                    'found_in_google' => $isFound ? 'yes' : 'NO - WILL DELETE'
                ]);
                
                if (!$isFound) {
                    // First, delete from export calendar if event was exported there
                    if ($exportCalendarId && $event->google_export_event_id && $service) {
                        $this->deleteFromExportCalendar($service, $exportCalendarId, $event);
                    }
                    
                    $this->logger->warning('Deleting event that was removed from Google Calendar', [
                        'event_id' => $event->id,
                        'google_event_id' => $event->google_event_id,
                        'title' => $event->title
                    ]);
                    $event->delete();
                    $deletedCount++;
                }
            }
            
            if ($deletedCount > 0) {
                $this->logger->info('Cleaned up deleted Google events', [
                    'deleted_count' => $deletedCount
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error cleaning up deleted Google events: ' . $e->getMessage());
        }
    }
    
    /**
     * Delete an event from the export calendar
     */
    private function deleteFromExportCalendar(\Google\Service\Calendar $service, string $exportCalendarId, CalendarEventsModel $event): void
    {
        try {
            $this->throttle();
            $service->events->delete($exportCalendarId, $event->google_export_event_id);
            $this->logger->info('Deleted event from export calendar', [
                'event_id' => $event->id,
                'google_export_event_id' => $event->google_export_event_id,
                'title' => $event->title
            ]);
        } catch (\Google\Service\Exception $e) {
            // 404 means already deleted - that's fine
            if ($e->getCode() !== 404) {
                $this->logger->error('Failed to delete event from export calendar', [
                    'event_id' => $event->id,
                    'google_export_event_id' => $event->google_export_event_id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
    
    /**
     * Auto-export an imported event to the export calendar
     */
    private function autoExportEvent(CalendarEventsModel $event, string $exportCalendarId): void
    {
        $this->logger->info('autoExportEvent called', [
            'event_id' => $event->id,
            'title' => $event->title,
            'published' => $event->published,
            'export_calendar' => $exportCalendarId,
            'existing_export_id' => $event->google_export_event_id ?: 'none'
        ]);
        
        try {
            // Use existing export ID if available for update
            $existingExportId = $event->google_export_event_id ?: null;
            
            $googleEventId = $this->syncEventToGoogle($event, $exportCalendarId, $existingExportId);
            
            if ($googleEventId) {
                $event->google_export_event_id = $googleEventId;
                $event->save();
                
                $this->logger->info('Auto-exported event successfully', [
                    'event_id' => $event->id,
                    'title' => $event->title,
                    'google_export_event_id' => $googleEventId
                ]);
            } else {
                $this->logger->warning('Auto-export returned null - event may have been skipped', [
                    'event_id' => $event->id,
                    'title' => $event->title,
                    'published' => $event->published
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to auto-export event', [
                'event_id' => $event->id,
                'title' => $event->title,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create Contao event from Google Calendar event
     * @return int Insert ID on success
     * @throws \Exception on failure
     */
    private function createContaoEvent(CalendarModel $calendar, Event $googleEvent, string $googleCalendarId, ?Event $masterEvent = null): int
    {
            $event = new CalendarEventsModel();
            $event->pid = $calendar->id;
            $event->tstamp = time();
            $event->title = $googleEvent->getSummary() ?: 'Untitled Event';
            $event->alias = \Contao\StringUtil::generateAlias($event->title) . '-' . uniqid();
            $event->teaser = $googleEvent->getDescription() ?: '';
            $event->location = $googleEvent->getLocation() ?: '';
            $event->author = 0; // System/no author
            
            // For recurring instances, store the base event ID so we can deduplicate
            $googleEventId = $googleEvent->getId();
            $recurringEventId = $googleEvent->getRecurringEventId();
            $event->google_event_id = $recurringEventId ?: $googleEventId;
            $event->google_updated = $googleEvent->getUpdated() ? strtotime($googleEvent->getUpdated()) : time();
            $event->google_event_origin = 'google'; // Mark as imported from Google
            $event->google_calendar_source = $googleCalendarId; // Store which calendar it was imported from

            // Parse start date/time
            $start = $googleEvent->getStart();

            if (!$start) {
                throw new \Exception('Google event has no start date: ' . $googleEvent->getId());
            }
            
            if ($start->getDateTime()) {
                $event->addTime = '1';
                // Parse Google's datetime and extract the local time as displayed in Google Calendar
                // This preserves the "visual" time - if Google shows 12:30pm, Contao will show 12:30pm
                $googleDateTime = new \DateTime($start->getDateTime());
                // Extract date and time components and recreate in server timezone
                $dateStr = $googleDateTime->format('Y-m-d');
                $timeStr = $googleDateTime->format('H:i:s');
                $event->startTime = strtotime("$dateStr $timeStr");
                $event->startDate = strtotime($dateStr);
            } else {
                $event->addTime = '';
                $event->startDate = strtotime($start->getDate());
                $event->startTime = $event->startDate;
            }

            // Parse end date/time
            $end = $googleEvent->getEnd();
            if ($end) {
                if ($end->getDateTime()) {
                    $googleEndDateTime = new \DateTime($end->getDateTime());
                    $endDateStr = $googleEndDateTime->format('Y-m-d');
                    $endTimeStr = $googleEndDateTime->format('H:i:s');
                    $event->endTime = strtotime("$endDateStr $endTimeStr");
                    $event->endDate = strtotime($endDateStr);
                } else {
                    // Google all-day events have end date as the day after
                    $endDate = strtotime($end->getDate());
                    $event->endDate = strtotime('-1 day', $endDate);
                    $event->endTime = $event->endDate;
                }
            } else {
                // No end date specified, use start date
                $event->endTime = $event->startTime;
                $event->endDate = $event->startDate;
            }

            // Parse recurring event settings
            // Use master event for recurrence info if available (singleEvents=true doesn't include RRULE on instances)
            $recurrenceSource = $masterEvent ?: $googleEvent;
            $this->parseRecurrence($event, $recurrenceSource);
            
            // Ensure proper types for database fields
            $event->addTime = $event->addTime ? 1 : 0;
            $event->recurring = $event->recurring ? 1 : 0;
            $event->published = 1;
            $event->recurrences = (int)($event->recurrences ?? 0);
            $event->repeatEnd = (int)($event->repeatEnd ?? 0);
            
            // Use Model save()
            $event->save();
            
            $insertId = $event->id;

            if (!$insertId) {
                throw new \Exception('Failed to insert event - no ID returned');
            }
            
            $this->logger->info('Successfully created Contao event from Google', [
                'event_id' => $insertId,
                'google_event_id' => $event->google_event_id,
                'title' => $event->title,
                'recurring' => $event->recurring ? 'yes' : 'no'
            ]);
            
            return $insertId;
    }

    /**
     * Parse Google Calendar RRULE and set Contao recurring fields
     */
    private function parseRecurrence(CalendarEventsModel $event, Event $googleEvent): void
    {
        $recurrence = $googleEvent->getRecurrence();
        if (!$recurrence || empty($recurrence)) {
            $event->recurring = 0;
            return;
        }

        // Parse RRULE lines (there can be multiple)
        $rrule = null;
        foreach ($recurrence as $line) {
            if (strpos($line, 'RRULE:') === 0) {
                $rrule = $line;
                break;
            }
        }
        
        if (!$rrule) {
            $this->logger->debug('No RRULE found in recurrence', [
                'recurrence' => $recurrence
            ]);
            $event->recurring = 0;
            return;
        }

        // Remove RRULE: prefix
        $rrule = substr($rrule, 6);
        $parts = [];
        foreach (explode(';', $rrule) as $part) {
            if (strpos($part, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $part, 2);
            $parts[$key] = $value;
        }

        // Check if we have a FREQ
        if (!isset($parts['FREQ'])) {
            $this->logger->warning('No FREQ found in RRULE', [
                'rrule' => $rrule
            ]);
            $event->recurring = 0;
            return;
        }

        $event->recurring = 1;

        // Map FREQ to Contao units
        $freqMap = [
            'DAILY' => 'days',
            'WEEKLY' => 'weeks',
            'MONTHLY' => 'months',
            'YEARLY' => 'years',
        ];

        $unit = $freqMap[$parts['FREQ']] ?? 'days';
        $interval = isset($parts['INTERVAL']) ? (int)$parts['INTERVAL'] : 1;

        // Set repeatEach as serialized array
        $event->repeatEach = serialize([
            'unit' => $unit,
            'value' => $interval,
        ]);
        
        // Log complex recurring patterns that Contao might not fully support
        if (isset($parts['BYDAY']) || isset($parts['BYMONTHDAY']) || isset($parts['BYSETPOS'])) {
            $this->logger->info('Complex recurring pattern detected', [
                'event_id' => $event->id,
                'title' => $event->title,
                'rrule' => $rrule,
                'note' => 'BYDAY/BYMONTHDAY/BYSETPOS patterns may not be fully supported in Contao'
            ]);
        }

        // Handle COUNT or UNTIL
        if (isset($parts['COUNT'])) {
            $event->recurrences = (int)$parts['COUNT'];
            $event->repeatEnd = 0;
            $this->logger->debug('Set recurring COUNT', [
                'count' => $event->recurrences
            ]);
        } elseif (isset($parts['UNTIL'])) {
            // Parse UNTIL date (format: YYYYMMDDTHHMMSSZ)
            $until = $parts['UNTIL'];
            // Remove Z if present and parse
            $until = rtrim($until, 'Z');
            if (strlen($until) === 8) {
                // Date only format YYYYMMDD
                $event->repeatEnd = strtotime($until);
            } else {
                // DateTime format YYYYMMDDTHHMMSS
                $event->repeatEnd = strtotime($until . ' UTC');
            }
            $event->recurrences = 0;
            $this->logger->debug('Set recurring UNTIL', [
                'until' => date('Y-m-d H:i:s', $event->repeatEnd)
            ]);
        } else {
            // No end specified - set a default far future date or leave empty
            $event->recurrences = 0;
            $event->repeatEnd = 0;
            $this->logger->debug('Recurring event with no end date');
        }
    }

    /**
     * Update Contao event from Google Calendar event
     */
    private function updateContaoEvent(CalendarEventsModel $event, Event $googleEvent, string $googleCalendarId): void
    {
        try {
            $event->tstamp = time();
            $event->title = $googleEvent->getSummary() ?: 'Untitled Event';
            $event->teaser = $googleEvent->getDescription() ?: '';
            $event->location = $googleEvent->getLocation() ?: '';
            $event->google_updated = $googleEvent->getUpdated() ? strtotime($googleEvent->getUpdated()) : time();
            $event->google_event_origin = 'google'; // Mark as updated from Google
            $event->google_calendar_source = $googleCalendarId; // Store which calendar it was imported from

            // Parse start date/time
            $start = $googleEvent->getStart();
            if (!$start) {
                $this->logger->error('Google event has no start date', [
                    'event_id' => $event->id,
                    'google_event_id' => $googleEvent->getId()
                ]);
                return;
            }
            
            if ($start->getDateTime()) {
                $event->addTime = 1;
                // Parse Google's datetime and extract the local time as displayed in Google Calendar
                $googleDateTime = new \DateTime($start->getDateTime());
                $dateStr = $googleDateTime->format('Y-m-d');
                $timeStr = $googleDateTime->format('H:i:s');
                $event->startTime = strtotime("$dateStr $timeStr");
                $event->startDate = strtotime($dateStr);
            } else {
                $event->addTime = 0;
                $event->startDate = strtotime($start->getDate());
                $event->startTime = $event->startDate;
            }

            // Parse end date/time
            $end = $googleEvent->getEnd();
            if ($end) {
                if ($end->getDateTime()) {
                    $googleEndDateTime = new \DateTime($end->getDateTime());
                    $endDateStr = $googleEndDateTime->format('Y-m-d');
                    $endTimeStr = $googleEndDateTime->format('H:i:s');
                    $event->endTime = strtotime("$endDateStr $endTimeStr");
                    $event->endDate = strtotime($endDateStr);
                } else {
                    $endDate = strtotime($end->getDate());
                    $event->endDate = strtotime('-1 day', $endDate);
                    $event->endTime = $event->endDate;
                }
            } else {
                // No end date specified, use start date
                $event->endTime = $event->startTime;
                $event->endDate = $event->startDate;
            }

            // Parse recurring event settings
            $this->parseRecurrence($event, $googleEvent);

            $event->save();
            
            $this->logger->info('Successfully updated Contao event from Google', [
                'event_id' => $event->id,
                'google_event_id' => $event->google_event_id,
                'title' => $event->title,
                'recurring' => $event->recurring ? 'yes' : 'no'
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error updating Contao event from Google', [
                'event_id' => $event->id,
                'google_event_id' => $googleEvent->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }
}
