# Migrate Events to Event Espresso

Developer notes for the add-on (`plugins/eea-event-migrator`; wordpress.org slug `migrate-events-to-event-espresso`, text domain the same). The user-facing description is `readme.txt`.

Migrates events, datetimes, venues, tickets, attendees, registrations, transactions and payments from other WordPress event plugins installed on the same site into Event Espresso. Data is read directly from the source plugin's tables and written into Event Espresso's models; nothing is exported to a file.

**Admin menu:** Event Espresso → Add-ons → Event Migrator
**Capability gate:** `ee_migrate_events`
**Core requirement:** Event Espresso Core `>= 5.0.58.001` (`EE_EVENT_MIGRATOR_CORE_VERSION_REQUIRED`), PHP `>= 7.4`
**Licensing:** free; no licence key, no entitlement check; runs on Event Espresso Decaf and on the caffeinated build.

## Supported sources

A source appears in the wizard when its plugin is installed. Versions other than the one tested are offered with a warning; run a dry run first.

| Source plugin | Tested version | Migrates |
|---|---|---|
| Events Manager | 7.4.8 | events, bookings, custom fields (payments with Events Manager Pro) |
| The Events Calendar | 6.18.1 | events |
| Event Tickets | 5.30.1 | events, bookings, payments, check-ins |
| Amelia | 2.4.11 | events, bookings, payments, custom fields |
| Eventin | 4.1.26 | events, bookings, payments, custom fields |
| Events Made Easy | 3.2.22 | events, bookings, payments, check-ins, custom fields |
| Event Genius | 1.19 | events, bookings, payments, custom fields |
| EventPrime | 4.3.5.1 | events, bookings, payments, custom fields |
| EventON Lite | 2.5.10 | events |
| WP Event Manager | 3.4.1 | events |
| WP Events Manager (ThimPress) | 2.2.5 | events, bookings, payments |

The Events Calendar and Event Tickets share their event posts: migrating both creates two Event Espresso events for an event that has Event Tickets tickets. Migrate the one that holds your bookings (Event Tickets), or roll one of the two runs back.

## Relationship to the Event Importer add-on

The CSV-based Event Importer (`eea-event-importer`) is a separate, independent add-on. Neither depends on the other. When both are installed, the migrator refuses to start while a CSV import lock is held (read-only check of the importer's lock option).

## Migrating from another plugin

Open **Event Espresso → Add-ons → Event Migrator**. The wizard has four steps:

1. **Source.** One card for every supported plugin installed on the site. A plugin that is installed but not active is shown greyed out with the reason; plugins that are not installed are not listed. When the CSV Event Importer is active a card links to it.
2. **Options.** A table of what the source holds for the options you choose (events, occurrences, venues, categories, tickets, contacts, bookings, payments), anything the source cannot supply, how it stores times, and the concepts that have no Event Espresso counterpart. Options:
   - *Which events*: all, upcoming only, or starting after a date.
   - *What to migrate*: events only, or events with their bookings (offered only when the source has bookings).
   - *Recurring events*: one event per series with a datetime per date, or one event per occurrence (offered only when the source has recurring events).
   - *Keep the source publish state*: shown only to users who may publish events. By default every event is created as a draft.
3. **Confirm.** A summary, then **Dry run** or **Begin migration**. Beginning needs the backup box ticked.
4. **Report.** What was created, reused, skipped and failed for every record type, compared with what the source held; warnings grouped by rule; existing records that were reused; changes to ticket sold counts; downloads.

Capability: every screen and action needs `ee_migrate_events` (administrators and Event Espresso events administrators have it). Only one migration, dry run, rollback or CSV import can run at a time; the wizard names the one that holds the lock, and offers to clear it when it has been held for more than two hours.

### What is migrated

| Source record | Event Espresso record |
|---|---|
| Event | Event (draft unless the publish state is kept), with its categories and featured image when it is in the media library |
| Date / occurrence | Datetime |
| Ticket or price | Ticket and price (a free or default ticket is created, with a warning, when the source has none) |
| Venue | Venue (a venue with the same name and address is reused) |
| Booking contact | Contact (matched by email, never duplicated) |
| Booking | Transaction and registrations, with the source status mapped to Event Espresso statuses |
| Payment | Payment, recorded as migrated; the transaction status is re-checked against what was paid |
| Custom field answers | Questions, a question group on the event, and answers |
| Check-in | Check-in |

Migration never sends email: registration, payment and admin notifications and the WordPress-user sync are switched off while it runs. The source plugin's tables are only read.

### Dry run

A dry run goes through the whole migration with the same options and writes nothing. Its report shows the same counts and warnings a real run would produce, so run one first and read the warnings.

### Runs, rollback and the crosswalk

Every run keeps a report. **Previous runs** lists them with links to the report, the crosswalk, the log and, for finished migrations, a rollback.

- **Crosswalk.** A CSV of `source, source_type, source_id, ee_model, ee_id, fingerprint` for every record the run created. It holds ids and hashes only, no personal data. Re-running a migration uses the same information to skip records that were already migrated.
- **Log.** Every warning by rule id and record id, never names or emails. Downloads need `ee_migrate_events` and go through a nonce-protected route; the files are not reachable by URL.
- **Rollback.** Deletes the records the run created and restores the ticket sold counts. Before anything is deleted every record is checked: if one was changed after the migration, or other records now depend on it, the rollback is refused, nothing is deleted, and the report lists the records. If some records cannot be deleted the run is marked *Rollback incomplete* and the rollback can be run again. Records that existed before the migration are never touched; a contact, venue, question or category the run created that other records now use is kept.

### Limitations

- Source plugins are read directly from their tables; versions the migrator was not tested with show a warning and should be tried with a dry run first.
- Event Espresso does not manage recurrence: a series becomes one event with several datetimes, or separate events.
- An image that is only a URL is reported, not downloaded.
- A source with more than 10,000 events in range is refused; narrow the date range and run again.
- The migration runs in your browser in small batches. Keep the page open until it finishes.

## Adding a source adapter

A new source plugin needs only a new adapter, with no change to the wizard, the batch engine, the crosswalk, rollback or the report (REQ-023):

1. Add a class named `<Source>SourceAdapter` implementing `SourceAdapter` (extend `SourceAdapterBase` for the shared read helpers) in its own snake_case directory under `src/domain/services/migration/sources/`, e.g. `events_manager/EventsManagerSourceAdapter.php`. The namespace follows the path, and the file name must end in `SourceAdapter.php` (the registry discovers adapters by that pattern). It supplies `slug()` (may be hyphenated), `name()`, `detect()` (adapters extending the base implement `detectSource()`), `capabilities()`, `supportedVersions()`, `inventory()`, `eventIds()`, `readEvent()`, `readBookings()`, `statusTable()` and `customFieldDefinitions()`. Reads are `SELECT` statements on the adapter's own tables only.
2. Nothing to register: `EE_Event_Migrator::after_registration()` adds a dependency-map entry (the adapter's one dependency, `TableAnalysis`) for every file `SourceAdapterRegistry::discoverClassNames()` finds, so the adapter's constructor must take only `TableAnalysis`. An adapter that needs more must add its own entry there.
3. Add a fixture (schema, seed rows and expected counts) and an adapter test under `tests/php/testcases/AoEventMigrator/`.
4. The wizard lists the source, the options step builds its table from `inventory()` and `capabilities()`, and the report uses the warning rules in `WarningRule`.

## Free add-on / Decaf

The migrator is free. It registers no licence key and no update channel, performs no entitlement check, and uses only core services, so it runs on Event Espresso Decaf as well as the caffeinated build.

## Uninstall

`uninstall.php` runs when the plugin is deleted: it removes the `ee_event_migrator_*` options (lock, config, run index, one option per run), the `ee_event_migrator_last_run` transient, the `uploads/espresso/event-migrator/` log folder and the `ee_migrate_events` capability, on every site of a network. Migrated Event Espresso records and their crosswalk meta are left alone.
