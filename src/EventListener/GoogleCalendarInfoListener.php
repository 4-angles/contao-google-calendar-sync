<?php

namespace FourAngles\ContaoGoogleCalendarBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use FourAngles\ContaoGoogleCalendarBundle\Service\GoogleCalendarService;
use Symfony\Component\Routing\RouterInterface;

/**
 * Add helpful messages to Google Calendar settings
 */
class GoogleCalendarInfoListener
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly GoogleCalendarService $googleService,
    ) {
    }

    #[AsCallback(table: 'tl_google_calendar_settings', target: 'config.onload')]
    public function onLoadSettings(DataContainer $dc): void
    {
        $authUrl = $this->router->generate('google_calendar_auth', [], RouterInterface::ABSOLUTE_URL);
        $status  = $this->googleService->getAuthStatus();

        $button = sprintf(
            '<a href="%s" class="tl_submit" style="display:inline-block;margin-top:6px;text-decoration:none">%s</a>',
            htmlspecialchars($authUrl, ENT_QUOTES, 'UTF-8'),
            'Re-authenticate with Google'
        );

        if ($status === 'none') {
            \Contao\Message::addInfo(
                '<strong>Not authenticated.</strong> No Google Calendar credentials found. ' . $button
            );
        } elseif ($status === 'expired') {
            \Contao\Message::addError(
                '<strong>Token expired or revoked.</strong> The stored Google OAuth token is no longer valid – ' .
                'automatic refresh failed (likely a test-mode app with a short-lived refresh token). ' . $button
            );
        } else {
            \Contao\Message::addConfirmation(
                '<strong>Authenticated.</strong> Google Calendar connection is active. ' . $button
            );
        }
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
