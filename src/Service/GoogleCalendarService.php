<?php

namespace App\Service;

use Contao\CalendarEventsModel;
use Contao\CalendarModel;
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
    private int $lastApiCall = 0;
    private int $minApiDelay = 500000; // 500ms in microseconds
    private int $maxRetries = 3;
    private int $oneYearInSeconds = 31536000; // 365 days
    private int $apiCallCount = 0;
    private int $currentMinute = 0;
    private int $maxCallsPerMinute = 590; // Stay under 600/minute limit

    public function __construct(string $projectDir, LoggerInterface $logger)
    {
        $this->credentialsPath = $projectDir . '/var/google-calendar-credentials.json';
        $this->logger = $logger;
    }

    /**
     * Initialize Google Client
     */
    public function getClient(): ?Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if (!$_ENV['GOOGLE_CALENDAR_ENABLED'] ?? false) {
            return null;
        }

        try {
            $this->client = new Client();
            $this->client->setApplicationName($_ENV['GOOGLE_CALENDAR_APPLICATION_NAME'] ?? 'Contao Calendar Sync');
            $this->client->setScopes(Calendar::CALENDAR);
            $this->client->setAuthConfig([
                'client_id' => $_ENV['GOOGLE_CALENDAR_CLIENT_ID'] ?? '',
                'client_secret' => $_ENV['GOOGLE_CALENDAR_CLIENT_SECRET'] ?? '',
                'redirect_uris' => [$_ENV['GOOGLE_CALENDAR_REDIRECT_URI'] ?? ''],
            ]);
            $this->client->setAccessType('offline');
            $this->client->setPrompt('select_account consent');

            // Load previously authorized credentials from file
            if (file_exists($this->credentialsPath)) {
                $accessToken = json_decode(file_get_contents($this->credentialsPath), true);
                $this->client->setAccessToken($accessToken);
            }

            // Refresh token if expired
            if ($this->client->isAccessTokenExpired()) {
                if ($this->client->getRefreshToken()) {
                    $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
                    $this->saveCredentials($this->client->getAccessToken());
                }
            }

            return $this->client;
        } catch (\Exception $e) {
            $this->logger->error('Google Calendar API Error: ' . $e->getMessage());
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
    public function syncEventToGoogle(CalendarEventsModel $event, string $googleCalendarId): ?string
    {
        // Skip unpublished events
        if (!$event->published) {
            $this->logger->debug('Skipping unpublished event', [
                'event_id' => $event->id,
                'event_title' => $event->title
            ]);
            return null;
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
        
        // Skip events more than 1 year in the future
        $eventStartDate = $event->startDate ?? $event->startTime ?? 0;
        $oneYearFromNow = time() + $this->oneYearInSeconds;
        if ($eventStartDate > $oneYearFromNow) {
            $this->logger->debug('Skipping event more than 1 year in advance', [
                'event_id' => $event->id,
                'event_title' => $event->title,
                'start_date' => date('Y-m-d', $eventStartDate)
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
        while ($retries <= $this->maxRetries) {
            try {
                // Rate limiting: ensure minimum delay between API calls
                $this->throttle();
                
                // Check if event already has a Google Calendar ID
                if ($event->google_event_id) {
                    // Update existing event
                    $updatedEvent = $service->events->update(
                        $googleCalendarId,
                        $event->google_event_id,
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
                if ($e->getCode() === 404 && $event->google_event_id) {
                    $this->logger->warning('Event not found in Google Calendar, clearing stale ID and recreating', [
                        'event_id' => $event->id,
                        'event_title' => $event->title,
                        'old_google_event_id' => $event->google_event_id
                    ]);
                    
                    // Clear the invalid google_event_id from model and database
                    $event->google_event_id = '';
                    
                    // Persist to database
                    Database::getInstance()
                        ->prepare('UPDATE tl_calendar_events SET google_event_id = ? WHERE id = ?')
                        ->execute('', $event->id);
                    
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
            $googleEvent->setSummary('Busy');
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

        // Set start date/time (always sent)
        $start = new EventDateTime();
        if ($event->addTime) {
            $start->setDateTime(date('c', $event->startTime));
            $start->setTimeZone(date_default_timezone_get());
        } else {
            $start->setDate(date('Y-m-d', $event->startDate));
        }
        $googleEvent->setStart($start);

        // Set end date/time (always sent)
        $end = new EventDateTime();
        if ($event->addTime) {
            $endTime = $event->endTime ?: $event->startTime;
            $end->setDateTime(date('c', $endTime));
            $end->setTimeZone(date_default_timezone_get());
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
        $service = $this->getService();
        if ($service === null) {
            return 0;
        }

        try {
            $syncCount = 0;
            // Sync only from today to 1 year ahead
            $optParams = [
                'maxResults' => 250,
                'orderBy' => 'startTime',
                'singleEvents' => false, // Get recurring event definitions
                'timeMin' => date('c'), // From today
                'timeMax' => date('c', strtotime('+1 year')), // Up to 1 year ahead
            ];

            // Rate limiting
            $this->throttle();
            $events = $service->events->listEvents($googleCalendarId, $optParams);

            foreach ($events->getItems() as $googleEvent) {
                // Check if event already exists in Contao
                $existingEvent = CalendarEventsModel::findOneBy(
                    ['google_event_id=?', 'pid=?'],
                    [$googleEvent->getId(), $calendar->id]
                );

                if ($existingEvent) {
                    // Check if Google event was updated
                    if ($googleEvent->getUpdated() && $existingEvent->google_updated) {
                        $googleUpdatedTime = strtotime($googleEvent->getUpdated());
                        $contaoUpdatedTime = $existingEvent->google_updated;

                        if ($googleUpdatedTime > $contaoUpdatedTime) {
                            $this->updateContaoEvent($existingEvent, $googleEvent);
                            $syncCount++;
                        }
                    }
                } else {
                    // Create new event in Contao
                    $this->createContaoEvent($calendar, $googleEvent);
                    $syncCount++;
                }
            }

            return $syncCount;
        } catch (\Exception $e) {
            $this->logger->error('Error syncing from Google Calendar: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Create Contao event from Google Calendar event
     */
    private function createContaoEvent(CalendarModel $calendar, Event $googleEvent): void
    {
        $event = new CalendarEventsModel();
        $event->pid = $calendar->id;
        $event->tstamp = time();
        $event->title = $googleEvent->getSummary() ?: 'Untitled Event';
        $event->teaser = $googleEvent->getDescription() ?: '';
        $event->location = $googleEvent->getLocation() ?: '';
        $event->google_event_id = $googleEvent->getId();
        $event->google_updated = strtotime($googleEvent->getUpdated());

        // Parse start date/time
        $start = $googleEvent->getStart();
        if ($start->getDateTime()) {
            $event->addTime = '1';
            $event->startTime = strtotime($start->getDateTime());
            $event->startDate = strtotime(date('Y-m-d', $event->startTime));
        } else {
            $event->addTime = '';
            $event->startDate = strtotime($start->getDate());
            $event->startTime = $event->startDate;
        }

        // Parse end date/time
        $end = $googleEvent->getEnd();
        if ($end->getDateTime()) {
            $event->endTime = strtotime($end->getDateTime());
            $event->endDate = strtotime(date('Y-m-d', $event->endTime));
        } else {
            // Google all-day events have end date as the day after
            $endDate = strtotime($end->getDate());
            $event->endDate = strtotime('-1 day', $endDate);
            $event->endTime = $event->endDate;
        }

        // Parse recurring event settings
        $this->parseRecurrence($event, $googleEvent);

        $event->published = '1';
        $event->save();
    }

    /**
     * Parse Google Calendar RRULE and set Contao recurring fields
     */
    private function parseRecurrence(CalendarEventsModel $event, Event $googleEvent): void
    {
        $recurrence = $googleEvent->getRecurrence();
        if (!$recurrence || empty($recurrence)) {
            $event->recurring = '';
            return;
        }

        // Parse first RRULE line
        $rrule = $recurrence[0];
        if (strpos($rrule, 'RRULE:') !== 0) {
            return;
        }

        // Remove RRULE: prefix
        $rrule = substr($rrule, 6);
        $parts = [];
        foreach (explode(';', $rrule) as $part) {
            [$key, $value] = explode('=', $part, 2);
            $parts[$key] = $value;
        }

        // Check if we have a FREQ
        if (!isset($parts['FREQ'])) {
            return;
        }

        $event->recurring = '1';

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

        // Handle COUNT or UNTIL
        if (isset($parts['COUNT'])) {
            $event->recurrences = (int)$parts['COUNT'];
            $event->repeatEnd = 0;
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
        } else {
            $event->recurrences = 0;
            $event->repeatEnd = 0;
        }
    }

    /**
     * Update Contao event from Google Calendar event
     */
    private function updateContaoEvent(CalendarEventsModel $event, Event $googleEvent): void
    {
        $event->tstamp = time();
        $event->title = $googleEvent->getSummary() ?: 'Untitled Event';
        $event->teaser = $googleEvent->getDescription() ?: '';
        $event->location = $googleEvent->getLocation() ?: '';
        $event->google_updated = strtotime($googleEvent->getUpdated());

        // Parse start date/time
        $start = $googleEvent->getStart();
        if ($start->getDateTime()) {
            $event->addTime = '1';
            $event->startTime = strtotime($start->getDateTime());
            $event->startDate = strtotime(date('Y-m-d', $event->startTime));
        } else {
            $event->addTime = '';
            $event->startDate = strtotime($start->getDate());
            $event->startTime = $event->startDate;
        }

        // Parse end date/time
        $end = $googleEvent->getEnd();
        if ($end->getDateTime()) {
            $event->endTime = strtotime($end->getDateTime());
            $event->endDate = strtotime(date('Y-m-d', $event->endTime));
        } else {
            $endDate = strtotime($end->getDate());
            $event->endDate = strtotime('-1 day', $endDate);
            $event->endTime = $event->endDate;
        }

        // Parse recurring event settings
        $this->parseRecurrence($event, $googleEvent);

        $event->save();
    }
}
