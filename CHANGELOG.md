### Added
- Bi-directional sync between Contao and Google Calendar
- Automatic cron-based synchronization (minutely import, hourly export)
- Manual sync buttons in backend
- Separate import/export calendar configuration to prevent sync loops
- Recurring events support with RRULE parsing
- Privacy mode (sync as "Busy" without details)
- Flexible configuration via backend UI or .env
- Built-in Google API rate limiting
- Configurable sync date window
- Auto-cleanup of deleted events
- Dynamic redirect URI generation (no manual URL configuration needed)
- Backend settings module for easy configuration

### Known Issues
- Complex RRULE patterns (BYDAY, BYMONTHDAY) may not sync perfectly
- Only first instance of recurring events is imported with singleEvents mode...
- Event attachments and attendees are not synced
- Absolute timezone from contao to google calendar
## [0.1.0] - 2026-02-12

### Added
- Initial development release
- Core synchronization functionality
- Google OAuth 2.0 authentication
- Contao 5.3+ compatibility

