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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class GoogleCalendarService
{
    private ?Client $client = null;
    private ?Calendar $service = null;
    private string $credentialsPath;
    private string $varDir;
    private LoggerInterface $logger;
    private ContaoFramework $framework;
    private UrlGeneratorInterface $router;
    private int $lastApiCall = 0;
    private int $minApiDelay = 100000; // 100ms in microseconds (Google allows 10 req/sec)
    private int $maxRetries = 3;
    private int $apiCallCount = 0;
    private int $currentMinute = 0;
    private int $maxCallsPerMinute = 590; // Stay under 600/minute limit

    public function __construct(string $projectDir, LoggerInterface $logger, ContaoFramework $framework, UrlGeneratorInterface $router)
    {
        $this->varDir = $projectDir . '/var';
        $this->credentialsPath = $this->varDir . '/google-calendar-credentials.json';
        $this->logger = $logger;
        $this->framework = $framework;
        $this->router = $router;
    }

    /**
     * Acquire an exclusive, non-blocking lock so two overlapping requests
     * (a double-click, a page refresh mid-sync, or a manual trigger
     * overlapping the cron) can't run the same sync/export concurrently -
     * that race is what causes duplicate Google events, since both requests
     * would otherwise see the same "not yet exported" state and both create
     * a new event.
     *
     * @return resource|null The open file handle to release via
     *                       releaseSyncLock() once the caller is done, or
     *                       null if the lock file itself couldn't be opened
     *                       (filesystem issue - proceeds unprotected rather
     *                       than blocking sync entirely).
     *
     * @throws \RuntimeException if another run already holds the lock.
     */
    private function acquireSyncLock(string $lockName)
    {
        if (!is_dir($this->varDir)) {
            mkdir($this->varDir, 0755, true);
        }

        $handle = fopen($this->varDir . '/google-calendar-' . $lockName . '.lock', 'c');
        if ($handle === false) {
            $this->logger->warning('Could not open sync lock file, proceeding without lock protection', [
                'lock_name' => $lockName,
            ]);
            return null;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('A sync for this calendar is already running - please wait for it to finish.');
        }

        return $handle;
    }

    /**
     * @param resource|null $lock
     */
    private function releaseSyncLock($lock): void
    {
        if ($lock !== null) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Initialize Google Client
     */
    public function getClient(): ?Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        try {
            // Get settings from database (takes precedence) or .env (fallback)
            $settings = $this->getSettings();
        

            $this->client = new Client();
            $this->client->setApplicationName($settings['application_name']);
            $this->client->setScopes(Calendar::CALENDAR);
            
            if (empty($settings['client_id']) || empty($settings['client_secret'])) {
                $this->logger->error('Google Calendar credentials are not configured');
                return null;
            }
            
            // Generate redirect URI dynamically from current environment
            $redirectUri = $this->router->generate('google_calendar_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
            
            $this->client->setAuthConfig([
                'client_id' => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
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
                    $refreshToken = $this->client->getRefreshToken();
                    
                    // Also check saved credentials for refresh token (it may not be in the current access token array)
                    if (!$refreshToken && !empty($accessToken['refresh_token'])) {
                        $refreshToken = $accessToken['refresh_token'];
                    }
                    
                    if ($refreshToken) {
                        $this->logger->info('Access token expired, refreshing automatically');
                        $newToken = $this->client->fetchAccessTokenWithRefreshToken($refreshToken);
                        
                        if (isset($newToken['error'])) {
                            $this->logger->error('Failed to refresh access token: ' . ($newToken['error_description'] ?? $newToken['error']));
                            // Token refresh failed - could be revoked or test-mode expiry
                        } else {
                            // Preserve the refresh token - Google doesn't always return it on refresh
                            if (empty($newToken['refresh_token'])) {
                                $newToken['refresh_token'] = $refreshToken;
                            }
                            $this->client->setAccessToken($newToken);
                            $this->saveCredentials($newToken);
                            $this->logger->info('Access token refreshed successfully');
                        }
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
     * Get Google Calendar settings from Contao config (priority) or .env (fallback)
     */
    private function getSettings(): array
    {
        $settings = [
            'client_id' => '',
            'client_secret' => '',
            'application_name' => 'Contao Calendar Sync'
        ];

        // Try to get settings from Contao config first
        $configClientId = \Contao\Config::get('googleCalendarClientId');
        $configClientSecret = \Contao\Config::get('googleCalendarClientSecret');
        $configApplicationName = \Contao\Config::get('googleCalendarApplicationName');

        if (!empty($configClientId)) {
            $settings['client_id'] = $configClientId;
            $this->logger->debug('Using Google Calendar Client ID from Contao config');
        }
        if (!empty($configClientSecret)) {
            $settings['client_secret'] = $configClientSecret;
            $this->logger->debug('Using Google Calendar Client Secret from Contao config');
        }
        if (!empty($configApplicationName)) {
            $settings['application_name'] = $configApplicationName;
            $this->logger->debug('Using Google Calendar Application Name from Contao config');
        }

        // Fall back to .env for any empty values
        if (empty($settings['client_id'])) {
            $settings['client_id'] = $_ENV['GOOGLE_CALENDAR_CLIENT_ID'] ?? '';
        }
        if (empty($settings['client_secret'])) {
            $settings['client_secret'] = $_ENV['GOOGLE_CALENDAR_CLIENT_SECRET'] ?? '';
        }
        if (empty($settings['application_name']) || $settings['application_name'] === 'Contao Calendar Sync') {
            $settings['application_name'] = $_ENV['GOOGLE_CALENDAR_APPLICATION_NAME'] ?? 'Contao Calendar Sync';
        }

        return $settings;
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
     * Get a lightweight auth status without initialising the full client.
     *
     * @return 'none'|'expired'|'ok'
     */
    public function getAuthStatus(): string
    {
        if (!file_exists($this->credentialsPath)) {
            return 'none';
        }

        $token = json_decode(file_get_contents($this->credentialsPath), true);
        if (empty($token['access_token'])) {
            return 'none';
        }

        $expiresAt = ($token['created'] ?? 0) + ($token['expires_in'] ?? 0);
        if ($expiresAt < time() + 60) {
            return 'expired';
        }

        return 'ok';
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
        $syncUntil = ($calendar && $calendar->google_sync_limit && $calendar->google_sync_until) ? (int)$calendar->google_sync_until : strtotime('+1 year');
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
        $lock = $this->acquireSyncLock('export-' . $calendar->id);

        try {
            return $this->doExportToGoogle($calendar, $googleCalendarId);
        } finally {
            $this->releaseSyncLock($lock);
        }
    }

    private function doExportToGoogle(CalendarModel $calendar, string $googleCalendarId): int
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

        // Get configurable sync date (default 1 year ahead, only when the limit checkbox is set)
        $syncUntil = ($calendar->google_sync_limit && $calendar->google_sync_until) ? (int)$calendar->google_sync_until : strtotime('+1 year');

        // Get all published events from the Contao calendar within the sync range
        $events = CalendarEventsModel::findBy(
            ['pid=?', 'published=?', 'startDate<=?'],
            [$calendar->id, 1, $syncUntil],
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

        // Diagnostic counters: how many events are excluded by each filter?
        // These make "why was this event not exported?" answerable from the logs.
        try {
            $unpublishedCount = (int) CalendarEventsModel::countBy(
                ['pid=?', 'published!=?', 'startDate<=?'],
                [$calendar->id, '1', $syncUntil]
            );
            $beyondSyncDateCount = (int) CalendarEventsModel::countBy(
                ['pid=?', 'published=?', 'startDate>?'],
                [$calendar->id, '1', $syncUntil]
            );
        } catch (\Exception $e) {
            $unpublishedCount = -1;
            $beyondSyncDateCount = -1;
        }

        // Pre-filter: figure out which events actually need a Google API call
        // (same guard clauses as syncEventToGoogle(), applied up front so we
        // can batch the actual API calls instead of one round-trip per event)
        $toSync = [];
        foreach ($events as $event) {
            if (!$event->published) {
                $skippedCount++;
                continue;
            }

            if ($event->google_event_origin === 'google' && $event->google_calendar_source === $googleCalendarId) {
                $skippedCount++;
                continue;
            }

            if ($event->recurring && $event->repeatEnd > 0 && $event->repeatEnd < time()) {
                $skippedCount++;
                continue;
            }

            $eventStartDate = $event->startDate ?? $event->startTime ?? 0;
            if ($eventStartDate > $syncUntil) {
                $skippedCount++;
                continue;
            }

            $existingExportId = $event->google_export_event_id ?: null;

            // Skip events that haven't been modified since last export
            if ($existingExportId && $event->google_updated && $event->tstamp <= $event->google_updated) {
                $skippedCount++;
                continue;
            }

            $toSync[] = ['event' => $event, 'existingId' => $existingExportId];
        }

        foreach (array_chunk($toSync, 50) as $chunk) {
            $batchResults = $this->syncEventBatch($service, $googleCalendarId, $chunk);

            foreach ($batchResults as $eventId => $result) {
                if (!$result['success']) {
                    $errorCount++;
                    $this->logger->error('Error exporting event to Google Calendar', [
                        'event_id' => $eventId,
                        'error' => $result['error'],
                    ]);
                    continue;
                }

                $event = CalendarEventsModel::findByPk($eventId);
                if (!$event) {
                    continue;
                }

                $event->google_export_event_id = $result['googleEventId'];
                $event->google_updated = time();
                if ($event->google_event_origin !== 'google') {
                    $event->google_event_origin = 'contao';
                }
                $event->save();

                $syncCount++;
                $this->logger->debug('Exported event to Google Calendar', [
                    'event_id' => $eventId,
                    'google_export_event_id' => $result['googleEventId']
                ]);
            }
        }

        $this->logger->info('Completed export to Google Calendar', [
            'calendar_id' => $calendar->id,
            'exported_count' => $syncCount,
            'skipped_unchanged' => $skippedCount,
            'error_count' => $errorCount,
            'published_candidates' => $events ? count($events) : 0,
            'unpublished_count' => $unpublishedCount,
            'beyond_sync_date_count' => $beyondSyncDateCount,
            'sync_until' => date('Y-m-d', $syncUntil)
        ]);

        return $syncCount;
    }

    /**
     * Create/update up to 50 events in a single batched HTTP request instead
     * of one round-trip per event. Handles partial failure per item: a rate
     * limit hit on one item retries only that item (never an already-
     * succeeded create, which would otherwise duplicate it), and a stale
     * export ID (404 on update) is cleared and retried as a fresh create -
     * the same self-heal syncEventToGoogle() does for the single-event path.
     *
     * @param array<int, array{event: CalendarEventsModel, existingId: ?string}> $items
     * @return array<int, array{success: bool, googleEventId: ?string, error: ?string}> keyed by Contao event id
     */
    private function syncEventBatch(Calendar $service, string $googleCalendarId, array $items): array
    {
        $client = $this->getClient();
        if ($client === null) {
            $results = [];
            foreach ($items as $item) {
                $results[$item['event']->id] = ['success' => false, 'googleEventId' => null, 'error' => 'Google client unavailable'];
            }
            return $results;
        }

        $results = [];
        $pending = $items;
        $retries = 0;

        $client->setUseBatch(true);

        try {
            while ($pending && $retries <= $this->maxRetries) {
                $this->throttle();

                $batch = $service->createBatch();
                $keyMap = [];

                foreach ($pending as $i => $item) {
                    $key = (string) $i;
                    $googleEvent = $this->convertContaoEventToGoogle($item['event']);

                    if ($item['existingId']) {
                        $batch->add($service->events->update($googleCalendarId, $item['existingId'], $googleEvent), $key);
                    } else {
                        $batch->add($service->events->insert($googleCalendarId, $googleEvent), $key);
                    }

                    $keyMap[$key] = $item;
                }

                $batchResponses = $batch->execute();
                $stillPending = [];
                $rateLimited = false;

                foreach ($keyMap as $key => $item) {
                    $responseKey = array_key_exists('response-' . $key, $batchResponses) ? 'response-' . $key : $key;
                    $result = $batchResponses[$responseKey] ?? null;
                    $eventId = $item['event']->id;

                    if ($result instanceof \Google\Service\Exception) {
                        // Stale export ID - clear it and retry as a create, not an update
                        if ($result->getCode() === 404 && $item['existingId']) {
                            $this->logger->warning('Event not found in Google Calendar during batch export, clearing stale ID and recreating', [
                                'event_id' => $eventId,
                                'old_google_event_id' => $item['existingId'],
                            ]);
                            Database::getInstance()
                                ->prepare('UPDATE tl_calendar_events SET google_export_event_id = ? WHERE id = ?')
                                ->execute('', $eventId);
                            $item['existingId'] = null;
                            $stillPending[] = $item;
                            continue;
                        }

                        if ($result->getCode() === 403 && strpos($result->getMessage(), 'rateLimitExceeded') !== false) {
                            $rateLimited = true;
                            $stillPending[] = $item;
                            continue;
                        }

                        $results[$eventId] = ['success' => false, 'googleEventId' => null, 'error' => $result->getMessage()];
                        continue;
                    }

                    if ($result === null) {
                        $results[$eventId] = ['success' => false, 'googleEventId' => null, 'error' => 'No response from batch'];
                        continue;
                    }

                    $results[$eventId] = ['success' => true, 'googleEventId' => $result->getId(), 'error' => null];
                }

                $pending = $stillPending;

                if ($pending) {
                    $retries++;
                    if ($rateLimited && $retries <= $this->maxRetries) {
                        $waitTime = pow(2, $retries) * 1000000;
                        $this->logger->warning('Rate limit hit during export batch, retrying', [
                            'retry' => $retries,
                            'wait_ms' => $waitTime / 1000,
                            'pending_count' => count($pending),
                        ]);
                        usleep($waitTime);
                    }
                }
            }
        } finally {
            $client->setUseBatch(false);
        }

        foreach ($pending as $item) {
            $results[$item['event']->id] = ['success' => false, 'googleEventId' => null, 'error' => 'Max retries exceeded'];
        }

        return $results;
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
     * Permanently delete this Contao calendar's own exported events from
     * Google - and ONLY those. Deliberately does NOT list and wipe every
     * event in the target Google Calendar, because multiple Contao calendars
     * can (and here, do) share the same Google export calendar; blindly
     * clearing the whole Google calendar would delete every other Contao
     * calendar's events too. Scoping to the specific google_export_event_id
     * values this calendar's own events are tracked with keeps the purge
     * from touching anything it didn't put there.
     *
     * This does not touch Contao data - call clearGoogleTrackingForCalendar()
     * afterwards to reset the stale tracking IDs left on Contao events.
     *
     * Deletes are sent in batches of up to 50 (Google Calendar API's batch
     * limit) instead of one HTTP round-trip per event.
     */
    public function purgeCalendarEvents(CalendarModel $calendar, string $googleCalendarId): int
    {
        $service = $this->getService();
        $client = $this->getClient();
        if ($service === null || $client === null) {
            $this->logger->error('Cannot purge: Google Calendar service not available');
            return 0;
        }

        $result = Database::getInstance()
            ->prepare("SELECT google_export_event_id FROM tl_calendar_events WHERE pid=? AND google_export_event_id!=''")
            ->execute($calendar->id);

        $eventIds = array_values(array_unique($result->fetchEach('google_export_event_id')));

        if (empty($eventIds)) {
            $this->logger->info('Nothing to purge - no Google events tracked for this calendar', [
                'calendar_id' => $calendar->id,
                'google_calendar_id' => $googleCalendarId,
            ]);
            return 0;
        }

        $deletedCount = 0;
        $client->setUseBatch(true);

        try {
            foreach (array_chunk($eventIds, 50) as $chunk) {
                $deletedCount += $this->deleteEventBatch($service, $googleCalendarId, $chunk);
            }
        } finally {
            $client->setUseBatch(false);
        }

        $this->logger->warning('Purged this calendar\'s tracked events from Google Calendar', [
            'calendar_id' => $calendar->id,
            'google_calendar_id' => $googleCalendarId,
            'found_count' => count($eventIds),
            'deleted_count' => $deletedCount,
        ]);

        return $deletedCount;
    }

    /**
     * Delete up to 50 events in a single batched HTTP request, retrying the
     * whole chunk on a rate-limit response.
     *
     * @param string[] $eventIds
     */
    private function deleteEventBatch(Calendar $service, string $googleCalendarId, array $eventIds): int
    {
        $retries = 0;

        while ($retries <= $this->maxRetries) {
            $this->throttle();
            $batch = $service->createBatch();

            foreach ($eventIds as $i => $eventId) {
                $batch->add($service->events->delete($googleCalendarId, $eventId), (string) $i);
            }

            $results = $batch->execute();
            $deletedCount = 0;
            $rateLimited = false;

            foreach ($results as $result) {
                if (!($result instanceof \Google\Service\Exception)) {
                    $deletedCount++;
                    continue;
                }

                // 404/410 = already gone - treat as success.
                if (in_array($result->getCode(), [404, 410], true)) {
                    $deletedCount++;
                    continue;
                }

                if ($result->getCode() === 403 && strpos($result->getMessage(), 'rateLimitExceeded') !== false) {
                    $rateLimited = true;
                    continue;
                }

                $this->logger->error('Error deleting event in purge batch: ' . $result->getMessage());
            }

            if (!$rateLimited) {
                return $deletedCount;
            }

            $retries++;
            $waitTime = pow(2, $retries) * 1000000;
            $this->logger->warning('Rate limit hit during purge batch, retrying', [
                'retry' => $retries,
                'wait_ms' => $waitTime / 1000,
            ]);
            usleep($waitTime);
        }

        $this->logger->error('Purge batch failed after max retries due to rate limiting');
        return 0;
    }

    /**
     * Reset the google_export_event_id tracking field on Contao events after
     * a purge, so a later export sync recreates events instead of trying to
     * update Google IDs that no longer exist.
     */
    public function clearGoogleTrackingForCalendar(CalendarModel $calendar): int
    {
        $result = Database::getInstance()
            ->prepare("UPDATE tl_calendar_events SET google_export_event_id='', google_updated=0 WHERE pid=? AND google_export_event_id!=''")
            ->execute($calendar->id);

        return $result->affectedRows;
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
        $syncUntil = ($calendar && $calendar->google_sync_limit && $calendar->google_sync_until) ? (int)$calendar->google_sync_until : strtotime('+1 year');
        
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
            $rrule = $this->buildRRule($event, $syncUntil);
            if ($rrule) {
                $googleEvent->setRecurrence([$rrule]);
            }
        }

        return $googleEvent;
    }

    /**
     * Build RRULE string from Contao recurring event settings.
     *
     * @param int $syncUntil Timestamp the calendar's export sync horizon
     *                       (its configured "sync until" date, or the
     *                       rolling +1 year default). Always caps the
     *                       exported UNTIL so an event with no repeatEnd
     *                       set doesn't recur forever in Google Calendar -
     *                       without this, an open-ended Contao recurring
     *                       event produces an RRULE with no COUNT/UNTIL at
     *                       all, and Google expands it indefinitely.
     */
    private function buildRRule(CalendarEventsModel $event, int $syncUntil): ?string
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

        // Add COUNT if recurrences is set (an explicit finite count from the
        // admin - honored as-is; RRULE doesn't allow COUNT and UNTIL together)
        if ($event->recurrences > 0) {
            $rrule .= ';COUNT=' . $event->recurrences;
        } else {
            // No COUNT: always add an UNTIL, capped at the sync horizon, so
            // the exported recurrence can never run further into the future
            // than this calendar's export window - even if repeatEnd is
            // unset (fully open-ended) or set further out than the horizon.
            $until = ($event->repeatEnd > 0) ? min((int) $event->repeatEnd, $syncUntil) : $syncUntil;
            // Google Calendar expects UNTIL in UTC format: YYYYMMDDTHHMMSSZ
            $rrule .= ';UNTIL=' . gmdate('Ymd\\THis\\Z', $until);
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
        $lock = $this->acquireSyncLock('import-' . $calendar->id);

        try {
            return $this->doSyncFromGoogle($calendar, $googleCalendarId);
        } finally {
            $this->releaseSyncLock($lock);
        }
    }

    private function doSyncFromGoogle(CalendarModel $calendar, string $googleCalendarId): int
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
            
            // Get configurable sync date (default 1 year ahead, only when the limit checkbox is set)
            $syncUntil = ($calendar->google_sync_limit && $calendar->google_sync_until) ? (int)$calendar->google_sync_until : strtotime('+1 year');

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
            $event->location = $this->resolveImportedLocation($calendar, $googleEvent);
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
            $calendar = CalendarModel::findByPk($event->pid);
            if ($calendar) {
                $event->location = $this->resolveImportedLocation($calendar, $googleEvent);
            } else {
                $event->location = $googleEvent->getLocation() ?: '';
            }
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

    /**
     * Resolve location value for imported events.
     */
    private function resolveImportedLocation(CalendarModel $calendar, Event $googleEvent): string
    {
        if ($calendar->google_sync_import_location_override) {
            return (string) ($calendar->google_sync_import_location_text ?? '');
        }

        return $googleEvent->getLocation() ?: '';
    }
}
