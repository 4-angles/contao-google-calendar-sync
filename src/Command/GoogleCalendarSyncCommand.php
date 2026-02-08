<?php

namespace App\Command;

use App\Service\GoogleCalendarService;
use Contao\CalendarEventsModel;
use Contao\CalendarModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:google-calendar:sync',
    description: 'Sync Contao calendars with Google Calendar',
)]
class GoogleCalendarSyncCommand extends Command
{
    private GoogleCalendarService $googleService;
    private ContaoFramework $framework;

    public function __construct(GoogleCalendarService $googleService, ContaoFramework $framework)
    {
        parent::__construct();
        $this->googleService = $googleService;
        $this->framework = $framework;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('calendar-id', InputArgument::OPTIONAL, 'Specific calendar ID to sync')
            ->addOption('direction', 'd', InputOption::VALUE_REQUIRED, 'Sync direction: to-google, from-google, or bidirectional', 'bidirectional')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Perform a dry run without making changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->framework->initialize();

        $calendarId = $input->getArgument('calendar-id');
        $direction = $input->getOption('direction');
        $dryRun = $input->getOption('dry-run');

        if ($dryRun) {
            $io->warning('DRY RUN MODE - No changes will be made');
        }

        // Find calendars to sync
        if ($calendarId) {
            $calendars = CalendarModel::findBy('id', $calendarId);
        } else {
            $calendars = CalendarModel::findBy('google_sync_enabled', '1');
        }

        if (!$calendars) {
            $io->warning('No calendars found with Google sync enabled');
            return Command::SUCCESS;
        }

        $totalSynced = 0;
        $totalErrors = 0;

        foreach ($calendars as $calendar) {
            $io->section("Syncing calendar: {$calendar->title} (ID: {$calendar->id})");

            if (!$calendar->google_calendar_id) {
                $io->error('No Google Calendar ID configured');
                $totalErrors++;
                continue;
            }

            try {
                $syncDirection = $direction !== 'bidirectional' ? $direction : $calendar->google_sync_direction;

                // Sync FROM Google
                if ($syncDirection === 'from-google' || $syncDirection === 'from_google' || $syncDirection === 'bidirectional') {
                    $io->text('Syncing FROM Google Calendar...');
                    
                    if (!$dryRun) {
                        $count = $this->googleService->syncFromGoogle($calendar, $calendar->google_calendar_id);
                        $io->success("Imported $count events from Google Calendar");
                        $totalSynced += $count;
                    } else {
                        $io->info('[DRY RUN] Would import events from Google Calendar');
                    }
                }

                // Sync TO Google
                if ($syncDirection === 'to-google' || $syncDirection === 'to_google' || $syncDirection === 'bidirectional') {
                    $io->text('Syncing TO Google Calendar...');
                    
                    $events = CalendarEventsModel::findBy('pid', $calendar->id);
                    
                    if ($events) {
                        $count = 0;
                        foreach ($events as $event) {
                            if (!$dryRun) {
                                $googleEventId = $this->googleService->syncEventToGoogle(
                                    $event,
                                    $calendar->google_calendar_id
                                );
                                
                                if ($googleEventId) {
                                    $event->google_event_id = $googleEventId;
                                    $event->google_updated = time();
                                    $event->save();
                                    $count++;
                                }
                            } else {
                                $count++;
                            }
                        }
                        
                        if ($dryRun) {
                            $io->info("[DRY RUN] Would sync $count events to Google Calendar");
                        } else {
                            $io->success("Synced $count events to Google Calendar");
                            $totalSynced += $count;
                        }
                    } else {
                        $io->info('No events found with sync enabled');
                    }
                }

                // Update last sync timestamp
                if (!$dryRun) {
                    $calendar->google_last_sync = time();
                    $calendar->save();
                }

            } catch (\Exception $e) {
                $io->error("Sync failed: {$e->getMessage()}");
                $totalErrors++;
            }
        }

        $io->newLine();
        if ($dryRun) {
            $io->success('Dry run completed - no changes were made');
        } else {
            $io->success("Sync completed! Total events synced: $totalSynced, Errors: $totalErrors");
        }

        return Command::SUCCESS;
    }
}
