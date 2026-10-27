=== Migrate Events to Event Espresso ===
Contributors: eventespresso, garthkoyle, pebblo, charliespider, knazart
Tags: event espresso, events, migration, import, tickets
Requires at least: 6.2
Tested up to: 7.1.3
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Migrate events, venues, tickets, attendees and registrations into Event Espresso from Events Manager, The Events Calendar, Amelia, Eventin and more.

== Description ==

Migrate Events to Event Espresso copies event data from another event plugin on your site into [Event Espresso](https://eventespresso.com/). It reads the other plugin's own database tables and posts. It then creates the matching Event Espresso events, dates, venues, tickets, attendees, registrations, transactions and payments. It does not export a file. It does not send data anywhere. It does not change the other plugin's data.

It can migrate from these plugins:

* Events Manager (events, bookings and custom fields; payments with Events Manager Pro)
* The Events Calendar (events)
* Event Tickets (events, bookings, payments and check-ins)
* Amelia (events, bookings, payments and custom fields)
* Eventin (events, bookings, payments and custom fields)
* Events Made Easy (events, bookings, payments, check-ins and custom fields)
* Event Genius (events, bookings, payments and custom fields)
* EventPrime (events, bookings, payments and custom fields)
* EventON (events)
* WP Event Manager (events)
* WP Events Manager by ThimPress (events, bookings and payments)

**What it migrates**

* Events: title, description, excerpt, author, featured image, categories, venue and publish state.
* Dates: every date of an event, converted from the source plugin's time format to your site's timezone. An all-day event becomes 00:00 to 23:59:59 in site time.
* Tickets: name, price, quantity, sale window and per-order limits. If the source plugin has no tickets, the migration creates one General Admission ticket, or one free ticket, so that each migrated event can take registrations.
* Venues: an existing Event Espresso venue with the same details is reused. No duplicate is created.
* Attendees: an existing Event Espresso contact with the same email is reused.
* Registrations: the registration status is mapped to the Event Espresso status. Answers to custom questions, check-ins, transactions and payments come with the registration.

**How a migration runs**

1. Go to Event Espresso → Add-ons → Event Migrator. Select the source plugin. The page lists only the plugins that are installed on the site.
2. Select the options. You can migrate all events, upcoming events only, or events that start after a date. You can migrate events only, without bookings. You can keep the publish state from the source plugin, or create all events as drafts. The page shows what the source plugin holds and what has no Event Espresso equivalent.
3. Run a dry run first. The dry run reports what the migration would create and which warnings it would raise. It writes nothing.
4. Run the migration. It runs in the background in small batches, so large sites complete without a timeout. Migrated events are drafts unless you chose to keep the publish state.
5. Read the report. It shows the count for each record type and each warning with a rule id. You can download the crosswalk, which lists the source record for each Event Espresso record, and the run log.

If you run the same migration again, the migration skips every record that it migrated before. You can migrate, check the result, and then migrate the rest.

**Rollback**

You can roll back each migration run from its report. The rollback removes only the records that the run created. If a migrated record was edited after the migration, or if it received a registration that the migration did not create, the rollback stops and names that record. Work done after the migration is never lost.

The plugin sends no emails and no Event Espresso messages during a migration or a rollback.

**Requirements**

* [Event Espresso](https://wordpress.org/plugins/event-espresso-decaf/) 5.0.58 or newer. The free Event Espresso Decaf and the paid Event Espresso both work.
* The source plugin, installed on the same site. It does not need to be active.
* The `ee_migrate_events` capability. Administrators get it on activation.

The plugin is free. It has no licence key, no paid tier and no external service. It does not connect to any server.

== Installation ==

1. Install and activate Event Espresso 5.0.58 or newer.
2. Install and activate Migrate Events to Event Espresso.
3. Go to Event Espresso → Add-ons → Event Migrator.
4. Select the source plugin. Run a dry run. Then run the migration.

== Frequently Asked Questions ==

= Does it change or delete the other plugin's data? =

No. The source plugin's tables and posts are only read. You can keep the source plugin, deactivate it, or remove it after the migration.

= Can I undo a migration? =

Yes. Open the run's report and choose Roll back. Every record that run created is removed. If any of those records was edited after the migration or has a new registration, the rollback refuses and tells you which records are affected.

= What happens to times when my site and the source plugin use different timezones? =

Every date is converted to your site's timezone from the source plugin's own storage model. An event whose own timezone differs from the site's is migrated with a warning that names both zones.

= Will attendees receive emails? =

No. Email and Event Espresso messages are suppressed for the whole run and for rollbacks.

= Which Event Espresso records are created for a plugin that has no tickets? =

One "General Admission" ticket with the event's price and capacity, or a free ticket when there is no price, so the event can take registrations straight away.

= I have both The Events Calendar and Event Tickets. Which do I migrate? =

They share their event posts, so migrating both creates two Event Espresso events for an event that sells tickets through Event Tickets. Migrate Event Tickets, which holds the bookings, or roll one of the two runs back.

= Where are the report and log kept? =

In the Event Espresso uploads folder on your own site, readable only by users with the migration capability. They are never sent anywhere.

= What happens when I delete the plugin? =

Deleting the plugin removes its own settings, run records, logs and the migration capability. The events, venues, tickets, contacts, registrations and payments a migration created stay in Event Espresso. Roll a run back before you delete the plugin if you want those removed.

== Changelog ==

= [1.0.0] =

**Added**
* Initial release: migrate events, venues, tickets, attendees and registrations into Event Espresso from Events Manager, The Events Calendar, Event Tickets, Amelia, Eventin, Events Made Easy, Event Genius, EventPrime, EventON, WP Event Manager and WP Events Manager
* Dry runs, migration reports, crosswalk download and rollback
