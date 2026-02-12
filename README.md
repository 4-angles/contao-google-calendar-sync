# Contao Google Calendar Sync Bundle

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

> **⚠️ Development Status**: This extension is currently in active development. While it should be stable for production use, please test thoroughly in your environment before deploying. Report any issues on the project repository.

Bi-directional synchronization between Contao CMS calendars and Google Calendar. Import events from Google Calendar into Contao and export Contao events to Google Calendar with automatic scheduling via cron jobs.

## Version control

I will always publish releases version based on which Contao version they support, so if its 5.3.0 it supports 5.3.* Contao version, etc...

## Features

- ✅ **Bi-directional Sync**: Import from Google Calendar to Contao and export Contao events to Google Calendar
- ✅ **Automatic Sync**: Configurable cron jobs (minutely import, hourly export)
- ✅ **Manual Sync**: On-demand import/export buttons in the backend
- ✅ **Separate Calendars**: Different calendars for import and export to prevent sync loops
- ✅ **Recurring Events**: Full support for recurring events (RRULE parsing)
- ✅ **Privacy Mode**: Option to sync events as "Busy" without details
- ✅ **Flexible Configuration**: Configure via backend UI or .env file
- ✅ **Rate Limiting**: Built-in Google API rate limit handling
- ✅ **Sync Window**: Configure date range for event synchronization
- ✅ **Auto-cleanup**: Removes deleted events from both systems

## Requirements

- Contao 5.3+
- PHP 8.1+
- Google Cloud Project with Calendar API enabled
- OAuth 2.0 credentials from Google Cloud Console

## Installation

### 1. Google Cloud Console Setup

#### 1.1 Create a Google Cloud Project

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select an existing one
3. Note your Project ID for reference

#### 1.2 Enable Google Calendar API

1. In your Google Cloud Project, navigate to **APIs & Services** → **Library**
2. Search for "Google Calendar API"
3. Click **Enable**

#### 1.3 Configure OAuth Consent Screen

1. Go to **APIs & Services** → **OAuth consent screen**
2. Choose **External** (or Internal for Google Workspace domains)
3. Fill in the required information:
   - **App name**: Your application name (e.g., "Contao Calendar Sync")
   - **User support email**: Your email address
   - **Developer contact information**: Your email address
4. Click **Save and Continue**
5. **Scopes**: Click "Add or Remove Scopes"
   - Add: `https://www.googleapis.com/auth/calendar` (See, edit, share, and permanently delete all calendars)
6. Click **Save and Continue**
7. **Test users** (for External apps in testing): Add your Google account email
8. Click **Save and Continue**

#### 1.4 Create OAuth 2.0 Credentials

1. Go to **APIs & Services** → **Credentials**
2. Click **Create Credentials** → **OAuth client ID**
3. Select **Application type**: **Web application**
4. **Name**: Give it a descriptive name (e.g., "Contao Calendar OAuth")
5. **Authorized redirect URIs**: Add your callback URL
   - Format: `https://yourdomain.com/contao/google-calendar-callback`
   - **Note**: The redirect URI is now generated automatically, so you just need to add it here in Google Console
6. Click **Create**
7. Copy the **Client ID** and **Client Secret** - you'll need these for configuration

### 2. Bundle Installation

Install via Composer:

```bash
composer require 4-angles/contao-google-calendar-bundle
```

Or manually:
1. Copy the bundle files to your Contao installation
2. Run `composer install` to install dependencies
3. Clear the cache: `php vendor/bin/contao-console cache:clear`

### 3. Configuration

You can configure the bundle in two ways (backend UI takes precedence):

#### Option A: Backend UI (Recommended for End Users)

1. Log in to Contao backend
2. Navigate to **System** → **Google Calendar Settings**
3. Enter your credentials:
   - **Google Client ID**: From Google Cloud Console
   - **Google Client Secret**: From Google Cloud Console
   - **Application Name**: (Optional) Custom name shown during OAuth
4. Save

#### Option B: Environment Variables (.env)

Add to your `.env.local` file:

```env
# Google Calendar API Configuration
GOOGLE_CALENDAR_CLIENT_ID=your-client-id.apps.googleusercontent.com
GOOGLE_CALENDAR_CLIENT_SECRET=your-client-secret
GOOGLE_CALENDAR_APPLICATION_NAME="Contao Calendar Sync"
```


### 4. Authentication

1. Navigate to **Content** → **Calendars**
2. Edit a calendar
3. Enable **Google Calendar Sync**
4. Select calendars for (you can select only import, or only export):
   - **Import**: Google Calendar to import FROM
   - **Export**: Google Calendar to export TO
5. You'll be prompted to authenticate with Google (click link in the message)
6. Grant access to your Google Calendar

**Important**: Use different calendars for import and export to prevent sync loops!

## Cron Job Setup

The bundle provides automatic synchronization via Contao's cron system:

### Manual Cron Setup (Production)

For production environments, set up a system cron job for better reliability:

```bash
# Edit crontab
crontab -e

# Add this line (runs every minute)
* * * * * cd /path/to/contao && php vendor/bin/contao-console google-calendar:sync >> /dev/null 2>&1
```

### Cron Schedule Details

**Import (Every Minute)**
- Pulls events from Google Calendar to Contao
- Only syncs future events (from today onwards)
- Respects the configured sync window
- Updates existing events if changed in Google

**Export (Every Hour)**
- Pushes Contao events to Google Calendar
- Only exports published events
- Skips events that haven't been modified (if they exist in Google calendar)
- Deletes unpublished/expired events from Google

### Manual Console Commands

You can also trigger sync manually:

```bash
# Sync all calendars
php vendor/bin/contao-console google-calendar:sync

# Sync specific calendar **Work in Progress**
php vendor/bin/contao-console google-calendar:sync 5

# Import only
php vendor/bin/contao-console google-calendar:sync --import-only

# Export only
php vendor/bin/contao-console google-calendar:sync --export-only

# Dry run (no changes)
php vendor/bin/contao-console google-calendar:sync --dry-run
```

## Usage

### Calendar Configuration

For each calendar you want to sync:

1. Go to **Content** → **Calendars**
2. Edit the calendar
3. In the **Google Sync** section:
   - ✅ **Enable Google Calendar Sync**
   - **Import Calendar**: Select Google Calendar to import FROM
   - **Export Calendar**: Select Google Calendar to export TO
   - **Sync Until**: Date range limit (default: 1 year ahead)
   - **Privacy Mode**: Optionally sync as "Busy" without event details

### Manual Sync Buttons

In **Content** → **Calendar Events**:
- **Import from Google**: Manually trigger import (icon in toolbar)
- **Export to Google**: Manually trigger export (icon in toolbar)

## Configuration Options

### Calendar-Level Settings

| Field | Description |
|-------|-------------|
| **Google Sync Enabled** | Enable sync for this calendar |
| **Import Calendar ID** | Google Calendar to import events FROM |
| **Export Calendar ID** | Google Calendar to export events TO |
| **Sync Until** | Import/export events up to this date (default: +1 year) |
| **Sync as Busy** | Hide event details, show as "Busy" only |
| **Busy Text** | Custom text for busy events (default: "Busy") |

### Global Settings (System → Google Calendar Settings)

| Field | Description |
|-------|-------------|
| **Google Client ID** | OAuth Client ID from Google Cloud Console |
| **Google Client Secret** | OAuth Client Secret from Google Cloud Console |
| **Application Name** | Name shown during OAuth flow |

## Architecture

### Sync Logic

**Import Direction (Google → Contao)**
- Fetches events from Google Calendar API
- Creates new Contao events or updates existing ones
- Tracks events by `google_event_id`
- Marks events as `google_event_origin = 'google'`
- Stores source calendar in `google_calendar_source`
- Only syncs future events within configured date range
- Deletes Contao events if deleted in Google

**Export Direction (Contao → Google)**
- Exports published Contao events to Google
- Updates existing Google events or creates new ones
- Tracks exports by `google_export_event_id`
- Only exports modified events (checks `tstamp`)
- Deletes from Google when unpublished in Contao
- Skips events imported from the same calendar (loop prevention)

**Sync Loop Prevention**
- Events imported from Calendar A are NOT exported back to Calendar A
- Separate tracking IDs: `google_event_id` (import) vs `google_export_event_id` (export)
- Origin tracking: `google_event_origin` = 'google' or 'contao'

### Database Fields

Additional fields added to `tl_calendar_events`:

| Field | Purpose |
|-------|---------|
| `google_event_id` | Event ID in import calendar |
| `google_export_event_id` | Event ID in export calendar |
| `google_updated` | Timestamp of last sync |
| `google_event_origin` | 'google' or 'contao' |
| `google_calendar_source` | Source calendar ID (for imports) |

## Troubleshooting

### Authentication Issues

**Error: "Could not generate authorization URL"**
- Check that Client ID and Client Secret are configured
- Verify credentials in System → Google Calendar Settings

**Error: "Access token expired"**
- Re-authenticate: Go to calendar settings and save again
- Check `var/google-calendar-credentials.json` permissions

### Sync Issues

**Events not importing**
- Verify Google Calendar ID is correct
- Check that events are in the future (past events are not synced)
- Verify sync window (`Sync Until` date)
- Check logs: `var/logs/contao-*.log`

**Events not exporting**
- Ensure events are published in Contao
- Check that export calendar ID is different from import calendar ID
- Verify event hasn't been modified since last export

**Duplicate events**
- This can happen if using the same calendar for import AND export
- **Solution**: Always use separate calendars for import and export

### Rate Limiting

The bundle handles Google API rate limits automatically:
- Max 10 requests/second (100ms delay between calls)
- Max 590 requests/minute (waits if limit approached)
- Exponential backoff on errors

## Security Considerations

- OAuth credentials stored in `var/google-calendar-credentials.json`
- Client Secret can be hidden in backend (password field)
- Credentials in `.env` should not be committed to version control
- Add to `.gitignore`:
  ```
  .env.local
  /var/google-calendar-credentials.json
  ```

## Known Issues

### Current Limitations

1. **Complex Recurring Patterns**: Some advanced RRULE patterns (BYDAY, BYMONTHDAY, BYSETPOS) may not sync perfectly between systems due to differences in how Contao and Google Calendar handle recurring events

2. **Timezone Handling**: Events are stored in Europe/Berlin timezone by default. Multi-timezone support may need additional configuration

3. **All-Day Events**: Google Calendar all-day events use exclusive end dates (end date + 1 day), which is automatically handled but may cause confusion when viewing raw data

4. **Instance-Based Sync**: Recurring events with `singleEvents=true` only import the first instance to prevent duplicates. Changes to individual instances of recurring events may not sync

5. **OAuth Token Refresh**: If refresh token expires (rare), manual re-authentication is required. Token refresh should happen automatically in most cases

6. **Attachment/Image Sync**: Event attachments and images are not synced between systems

7. **Attendee/Guest Lists**: Attendee information is not synced


## TODO / Roadmap

- [ ] Add authentication status display in settings page
- [ ] Add "Re-authenticate" button in backend
- [ ] Better error messages for users (currently logged, not always shown)
- [ ] Add sync status dashboard/widget
- [ ] Implement batch operations for large event sets
- [ ] Support for event attachments
- [ ] Support for attendees/guest lists
- [ ] Multi-timezone support
- [ ] Event categories/tags mapping
- [ ] Sync event colors/labels

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests if applicable
5. Submit a pull request

## License

This project is licensed under the MIT License.

```
MIT License

Copyright (c) 2026 FourAngles

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

## Support

For issues, questions, or feature requests:
- Open an issue on the project repository
- Check existing issues for similar problems
- Provide detailed information: Contao version, PHP version, error messages, logs

## Credits

Developed by **Kimleta**

Built with:
- [Contao CMS](https://contao.org/)
- [Google Calendar API](https://developers.google.com/calendar)
- [Google API PHP Client](https://github.com/googleapis/google-api-php-client)

---

**⚠️ Important Reminder**: Always test in a development environment first. Back up your database before enabling sync on production calendars.
