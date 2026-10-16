# Sebo Call Tracking

Sends GoHighLevel number-pool calls to GA4 as a `phone_call` event, attached to
the caller's website session, so GA4 reports the call under the right source
and Google Ads can credit it through a GA4 conversion import.

For clients whose calls come through GHL number pools. Clients on CallRail
don't need it: CallRail has its own GA4 and Google Ads integrations.

## Why the session matters

GHL's number-pool script stores the visitor's GA client ID (the `_ga` cookie)
on the contact. It doesn't store the GA4 **session ID**, which lives in a
second cookie (`_ga_<STREAM>`). An event sent without the real session ID can't
be tied to the visit, so GA4 shows the call with no proper source, and Google
Ads can't credit the ad click. (The old Make scenario used part of the client
ID as the session ID, which is why calls were under-credited.)

This plugin fills the gap:

1. **`assets/sessions.js`** (about 1 KB, deferred) reads GA's own cookies on
   every page and, only when the session changes, posts
   `{client ID, stream, session ID}` to `POST /wp-json/sebo-call-tracking/v1/session`.
   It sets no cookies; it only waits for GA's (so it respects whatever consent
   gates GA).
2. **GHL** fires a standard Webhook on each incoming call to
   `POST /wp-json/sebo-call-tracking/v1/call?key=…`.
3. The plugin matches the caller's client ID to their latest session for each
   GA4 property, and sends `phone_call` with the Measurement Protocol:
   `session_id`, `engagement_time_msec`, and the custom parameters
   `call_source`, `call_medium`, `call_campaign`, `call_landing_page`,
   `tracking_number` and `gclid`, all from the same visit.

A caller with no recorded website visit (they found the number elsewhere) is
logged but not sent: GA4 needs a client ID, and a made-up one would invent a
visitor.

## Setup

### One website (most clients)

1. Install and activate the plugin.
2. **Settings > Call Tracking**: add the GA4 Measurement ID and a Measurement
   Protocol API secret (GA4 Admin > Data streams > web stream > Measurement
   Protocol API secrets > Create). Add more rows to send to more properties.
3. **GHL**: Automation > Workflows > new workflow. Trigger **Call Details**,
   Call Direction is Incoming (filter by number pool if the location has
   several). Action **Webhook**, POST, to the URL shown on the settings page.
   Optional Custom Data item `tracking_number` (the pool's name) is passed to
   GA4.
4. **GA4**: once the first call has arrived, Admin > Events > mark
   `phone_call` as a key event. Optionally register `call_source`,
   `tracking_number` and `call_landing_page` as event-scoped custom dimensions.
5. **Google Ads**: Goals > Conversions > New conversion action > Import >
   Google Analytics 4 properties > Web > `phone_call`. The GA4 property must be
   linked to the Ads account.

### Multisite

**Every site has its own webhook URL and key** (on its Settings > Call
Tracking). Calls posted there always belong to that site. Use this when the
sites are different clients: each client's GHL workflow can only post to its
own site.

**One business with several sites** (like Utah Family Lodges) can also use the
**network URL** (Network Admin > Settings > Call Tracking) for a single GHL
workflow covering every site. Calls are routed to the site whose address
matches the page the caller last visited (`domain/path` for subdirectory
sites, plus each site's "Other domains"). A `site` Custom Data item (site ID,
path or domain) overrides that. A call that matches no site goes to the network
properties only.

**Network properties** (same page) receive every site's calls, whichever URL
they arrive at: a roll-up. Leave them empty on a network of unrelated clients.

## Settings page

Shows the webhook URL (it contains the key, so keep it out of screenshots),
the GA4 properties (saved API secrets show as dots), and the last 30 calls:
result, source, and whether a session was matched for each property.

## WP-CLI

```bash
wp sebo-call-tracking status
wp sebo-call-tracking test                      # validate only: GA4's debug endpoint, nothing recorded
wp sebo-call-tracking test --landing=https://example.com/page/ --client-id=GA1.1.123.456
wp sebo-call-tracking test --send               # record a real event
```

The webhook also accepts `&validate=1` to check against GA4's debug endpoint.

## Data

Two network-wide tables: `{prefix}sebo_ct_sessions` (client ID, stream,
session ID, last seen; kept 30 days) and `{prefix}sebo_ct_log` (one row per
call, no personal details beyond the GHL contact ID; kept 90 days). A daily
cron prunes both.

## Updates

Sites update from this repository's GitHub releases, like any plugin. No token
or setup is needed (the repository is public).

To release: bump `Version:` and `SEBO_CT_VERSION` in `sebo-call-tracking.php`,
push to `main`, then publish a GitHub release tagged `vX.Y.Z` with
`sebo-call-tracking.zip` attached (`bash bin/build-zip.sh`).

## Tests

```bash
node tests/sessions.test.js
```

Cookie parsing (`GA1.1`/`GA1.2` client IDs, `GS1`/`GS2` session cookies) and
when the script reports. The PHP side was verified on the Utah Family Lodges
network (WordPress multisite, PHP 8.3): session capture from a real browser,
routing to a subdirectory site, session matching, GA4 debug-endpoint
validation, and webhook key checks.
