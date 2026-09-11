<?php

// contao/dca/tl_google_calendar_purge.php
// Dedicated backend module for purging (permanently deleting) all events
// from a calendar's configured Google export calendar.
//
// Deliberately its own DC_File-based module (same pattern as
// tl_google_calendar_settings) instead of a custom controller route, so it
// goes through Contao's normal backend authentication, per-module user/group
// permissions and CSRF protection like any other backend screen.

$GLOBALS['TL_DCA']['tl_google_calendar_purge'] = [
    'config' => [
        'dataContainer' => \Contao\DC_File::class,
        'closed' => true,
        'notEditable' => false,
    ],
    'palettes' => [
        'default' => '{purge_legend},calendars',
    ],
    'fields' => [
        'calendars' => [
            'label' => &$GLOBALS['TL_LANG']['tl_google_calendar_purge']['calendars'],
            'inputType' => 'checkbox',
            'options_callback' => ['tl_google_calendar_purge', 'getEligibleCalendars'],
            'eval' => ['multiple' => true, 'tl_class' => 'clr'],
        ],
    ],
];

$GLOBALS['TL_DCA']['tl_google_calendar_purge']['config']['onsubmit_callback'][] = ['tl_google_calendar_purge', 'handlePurge'];

/**
 * Provide callbacks for the Google Calendar purge module
 */
class tl_google_calendar_purge extends \Contao\Backend
{
    /**
     * List calendars that have Google sync enabled with an export calendar
     * configured - the only ones a purge can meaningfully run against.
     */
    public function getEligibleCalendars(): array
    {
        $options = [];
        $calendars = \Contao\CalendarModel::findBy('google_sync_enabled', '1');

        if ($calendars) {
            foreach ($calendars as $calendar) {
                if ($calendar->google_calendar_id_export) {
                    $options[$calendar->id] = $calendar->title;
                }
            }
        }

        return $options;
    }

    /**
     * Purge every selected calendar's Google export calendar and report the
     * outcome via the normal Contao backend message system.
     */
    public function handlePurge(\Contao\DataContainer $dc): void
    {
        $selectedIds = array_map('intval', (array) \Contao\Input::post('calendars'));

        if (!$selectedIds) {
            return;
        }

        $container = \Contao\System::getContainer();
        $googleService = $container->get('FourAngles\ContaoGoogleCalendarBundle\Service\GoogleCalendarService');
        $logger = $container->get('monolog.logger.contao.general');

        $summary = [];

        foreach ($selectedIds as $id) {
            $calendar = \Contao\CalendarModel::findByPk($id);
            if (!$calendar || !$calendar->google_sync_enabled || !$calendar->google_calendar_id_export) {
                continue;
            }

            try {
                $deletedCount = $googleService->purgeCalendarEvents($calendar, $calendar->google_calendar_id_export);
                $clearedCount = $googleService->clearGoogleTrackingForCalendar($calendar);

                $summary[] = sprintf(
                    '%s: deleted %d event(s), cleared %d tracking reference(s)',
                    $calendar->title,
                    $deletedCount,
                    $clearedCount
                );

                $logger->warning('Purged Google Calendar via purge module', [
                    'calendar_id' => $calendar->id,
                    'deleted_count' => $deletedCount,
                    'cleared_count' => $clearedCount,
                ]);
            } catch (\Exception $e) {
                $summary[] = sprintf('%s: failed - %s', $calendar->title, $e->getMessage());
                $logger->error('Purge module error: ' . $e->getMessage(), ['calendar_id' => $calendar->id]);
            }
        }

        if ($summary) {
            \Contao\Message::addConfirmation(implode('<br>', array_map(
                fn(string $line) => \Contao\StringUtil::specialchars($line),
                $summary
            )));
        }
    }
}
