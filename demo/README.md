# Product Success Platform: demo

A working model of the [spec](../SPEC.md). It's here to make the ideas concrete enough to argue about, not to ship. Every person, site and number in it is invented, and every domain uses the reserved `.example` name.

**Just want to look?** A copy runs in your browser at <https://saifhassan-am.github.io/product-success-platform/demo/>, with no install. It's a snapshot: anything you log stays in your tab, and the Live switch and simulator need the local version below.

## Run it

Needs PHP 8.1 or newer with the `pdo_sqlite` and `curl` extensions (macOS Homebrew PHP has both). No Composer, no npm, no database server.

```bash
cd demo
```

```bash
php bin/seed.php
```

```bash
php -S 127.0.0.1:8787 -t public public/router.php
```

Then open <http://127.0.0.1:8787/console/index.html>.

## Watch a site move through the stages

With the server running, open the console, switch on **Live**, choose **All opted-in sites** and sort by **Recently active**. Then, in another terminal:

```bash
php bin/simulate.php
```

It walks one fake site, Lumen Pottery, through Thrive Leads using the real WordPress-side client and integration, pausing a few seconds between steps:

1. Activates Thrive Leads. Nothing is sent, because nobody has consented.
2. Opts in to "Help me succeed" during onboarding. Registers, and reports a new, timed activation.
3. Creates a first campaign (Built).
4. The owner previews it while logged in. Doesn't count.
5. A real visitor sees it (Live).
6. The owner tests the form logged out with their own email. One person, so not First value yet.
7. A real visitor signs up. Two different people: First value.
8. A restored backup re-sends the milestone. The server ignores it.
9. PHP goes from 8.1 to 8.3. The profile is overwritten in place.
10. WordPress updates to 7.1. Same.
11. The weekly heartbeat goes out.
12. The owner turns off "Help me succeed." The site's identified data is deleted.

Flags: `--fast` (no pauses), `--delay=2` (seconds per step), `--keep` (stop before step 12 so the site stays in the console).

## Things to try in the console

* **Today's focus:** the 15 accounts most likely to respond. Open one in the **Contact** group and one marked **Holdout: don't contact**, and compare what you're allowed to do.
* **The funnel:** select a stage to see who's stuck there. The count beside the bar matches the list.
* **The pilot panel:** "Too early to read," with a projected finish date. At the demo's small scale that date is years away, which is the supply problem the plan warns about.
* **Product role:** names, emails and addresses disappear, and sites show as IDs, the way Metabase will see them.
* **Access log:** every look at a customer's details, and every export.
* **Export list:** a CSV with account IDs and stages, no contact details.

## What's in here

| Path | What it is |
|---|---|
| `client/class-thrive-success-client.php` | The WordPress-side client. In production it would live in Thrive Dashboard, shared by every product. Consent tiers with separate IDs, a local outbox, background sending, profile change detection, backfill at opt-in, staging-clone detection. |
| `client/integrations/thrive-leads.php` | How Thrive Leads reports its moments, using hooks Leads already fires. Filters out admins, counts First value at two distinct people, keeps visitor emails on the site. |
| `client/wp-shim.php` | Just enough fake WordPress for the simulator to run the client from the command line. |
| `src/Ingest.php` | Registration with a per-site token, collecting events, the latest-wins profile, withdrawal with deletion. |
| `src/StageEngine.php` | Turns events into stage, health inputs and Adopted. "First" is enforced here, not in the plugin. |
| `src/Console.php` | Everything the console reads: health, days in stage, thresholds learned from data, priority, one row per account, pilot randomization and outcomes, blockers, masking by role, access log. |
| `src/Products.php` | Each product's mapping onto the five stages, default thresholds, and the playbook copy. |
| `src/schema.sql` | The database. |
| `public/console/` | The console: plain HTML, CSS and JavaScript, no build step. |
| `public/console/static-api.js` | Answers the console's requests in the browser, for the GitHub Pages copy. Mirrors the filtering, grouping, sorting and masking in `src/Console.php`. |
| `bin/seed.php` | Generates the fake data, through the real ingestion code. |
| `bin/simulate.php` | The live walkthrough above. |
| `bin/export-static.php` | Writes the seeded database out as JSON for the browser-only copy. |

## How this differs from production

| Here | In production |
|---|---|
| Identified sites register with just an account ID | The site proves its account connection with the existing signed account request |
| Accounts are seeded into the demo database | Name, email and plan come from the store and account system |
| No consent screens; the simulator calls the consent change directly | Consent v2 screens, approved by legal |
| The Leads integration's SQL is illustrative | Engineering confirms the exact tables and columns |
| SQLite, one process | Whatever engineering chooses for the real ingestion service |
| A role switch in the header | Real sign-in and roles |
| A few hundred accounts | Tens of thousands |

## Notes for engineering

Found while building, worth knowing before the real thing:

* **Campaigns are saved as published the moment they're created** in Thrive Leads, so post status can't tell you when a campaign went live. A real visitor's first impression can.
* **Leads' impression hook skips bots but not logged-in admins, and the conversion hook skips neither.** The integration has to filter admins itself, or the owner previewing their own site counts as "Live."
* **Conversions include button clicks.** Only email sign-ups should count toward First value.
* **Consent is always asked after activation.** Without the "opted in within a day of activating" rule, no activation would ever be timed.
* **SQLite compares a bound `'0'` as text**, which is always "greater" than `0`. A `CASE WHEN :value > 0` silently became always-true here and hid every "Slipping" site. Decide numeric conditions in code, or bind as integers.

## API (for reference)

Plugin-facing:

| Method | Path | Notes |
|---|---|---|
| POST | `/api/v1/register` | `{install_id, tier, account_id?}` returns a site token. Identified tier requires the account holder's "Help me succeed" consent. |
| POST | `/api/v1/collect` | Bearer token. `{events: [...], profile?: {...}}`. Duplicate event IDs are ignored; bad events are rejected one by one without failing the batch. |
| POST | `/api/v1/withdraw` | Bearer token. Deletes everything tied to the site. |

Console (role via `X-Demo-Role: support|pm`):

| Method | Path |
|---|---|
| GET | `/api/v1/console/products` |
| GET | `/api/v1/console/summary?product=tl` |
| GET | `/api/v1/console/worklist?product=tl&view=today|stuck|all&stage=&health=&q=&sort=` |
| GET | `/api/v1/console/sites/{install_id}?product=tl` |
| POST | `/api/v1/console/sites/{install_id}/outreach?product=tl` |
| GET | `/api/v1/console/export.csv?product=tl&view=stuck` |
| GET | `/api/v1/console/access-log` |
