# Changelog

All notable changes to `pushery/matomo-analytics-for-laravel` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.32.0] - 2026-10-04

### Added

- **An opt-in route for the hits only the browser sees, on a page that loads no `matomo.js`.** With `hit_beacon.enabled`, `@matomoHitBeacon` defines `window.matomoHit(type, data)`, and the route records an event, an outlink, a download, a site search or a heartbeat through the facade and the tracking gate, filed under the page that sent it. It takes requests from your own origin only, bounds every text field, accepts only absolute `http` and `https` links, and limits event categories to `hit_beacon.event_categories` when you name any. The throttle is `60,1` per client, counted per /64 for IPv6. Applications that built their own bridge for this can remove it.
- **The tracking gate refuses every hit of a speculative request, under the new reason `prefetch`.** `middleware.skip_prefetch` kept the middleware from counting a speculation-rules prefetch as a page view, but a hit your application sends while rendering such a request, an event for the `404` behind a prefetched broken link for instance, went through a gate that had no rule for it, and Matomo counted a page nobody had seen. `tracking.skip_prefetch`, on by default, refuses it. If you set `middleware.skip_prefetch` to `false` to count speculative loads, set `tracking.skip_prefetch` to `false` as well, or the gate refuses those page views now.

### Changed

- **`composer.json` suggests `ext-redis` and `ext-pcntl`.** The `redis` buffer driver calls into ext-redis when Laravel's Redis client is phpredis, and `matomo:work` stops between two runs on `SIGTERM` or `SIGINT` only where ext-pcntl defines them. Both are optional, and neither was named anywhere a consumer reads before installing.
- **The test fakes answer the way the real clients do.** `MatomoGdpr::fake()` clears `lastError()` on a successful call, so a lookup after a failed mutation succeeds again, and its export answers with rows keyed by log table, as Matomo's does, instead of under an `exported` key; `stubExported()` sets the whole answer. A method `MatomoReports::fake()` has no answer for now fails with a reason in `lastError()`, and an answer clears it. `MatomoAnnotations::fake()->add()` answers with the annotation's `id`, `idsite` and a date, today when none was given. A test that read the old shapes needs its expectation moved.
- **The package page on Packagist names its publisher and links the documentation.** `composer.json` carries `authors` and `support.docs`, the portal the README already links, so `composer show` and Packagist point to it as well.
- **The Boost skill no longer carries notes to its own author.** Three sentences told whoever maintains the skill what to keep out of it, and Laravel Boost loads the skill into your application's agent, where they read as instructions to that agent. The skill points to the full documentation instead.
- **Comments in the shipped code describe what the code does.** A few described how a change came about instead. Nothing a consumer calls, receives or configures changes.
- **`AnnotationsClient::annotateRelease()` takes the site as a third, optional argument**, as `add()` already did, and the `MatomoAnnotations` facade passes it through. A class of your own that implements the contract adds the parameter: `int|string|null $site = null`.
- **The AI crawler list recognizes five more agents from ai.robots.txt:** `AgentDataBot`, `BixelBot`, `CloudflareBrowserRenderingCrawler`, `Kimi-Agent` and `qodercli`. No entry was dropped. Traffic from these agents was counted as ordinary visits and no longer is, unless `bots.track` is on.
- **`matomo/matomo-php-tracker` is no longer suggested.** The suggestion offered it instead of the bundled HTTP client, but nothing in the package uses it: there is no adapter and no setting for it, so installing it changed nothing. To send through another client, bind your own `MatomoAnalytics\Contracts\Sender`.

### Performance

- **A tracked request no longer asks Redis for its eviction policy.** The `redis` buffer checks the policy once per process, and under PHP-FPM every request starts with fresh static state, so the driver sent a `CONFIG GET` before the `RPUSH` of every tracked request. The check runs on the flush side now, where a worker or a scheduled `matomo:flush` asks once.

### Fixed

- **`matomo:test` no longer reports a Redis it could not ask as one that loses the buffer on restart.** A `CONFIG` renamed away answers false under phpredis, and the persistence check read that as `appendonly` off. Both Redis checks now print that the setting is unchecked when `CONFIG GET` does not answer, and what to confirm with the provider instead; before, the eviction check printed nothing, which read as a safe policy.
- **The `redis` buffer driver names the reason it cannot run on Redis Cluster.** A claim moves hits between two keys, which a cluster refuses across hash slots, and the phpredis cluster client has no pipeline, so the first claim failed with an error that named neither. A cluster connection is now refused with that reason on push and on claim, and `matomo:test` shows it.
- **Several drainers on PostgreSQL or MySQL share a backlog instead of stopping early.** Concurrent claims selected the same first rows, one of them won, and a drainer that lost three times in a row ended its run: eight drainers started together delivered half of what they could, and four of them nothing. A claim now locks the rows it takes and skips the rows another claim holds (`FOR UPDATE SKIP LOCKED`). On MySQL it runs at `READ COMMITTED`, so it does not hold the batches other drainers are sending, which at `REPEATABLE READ` let an acknowledgment deadlock with it.
- **`flushCache()` invalidates the report cache when another process flushed in between.** A flush wrote the version this instance had read plus one, so after flushes elsewhere it could move the version back and make reports cached under an older version current again, for up to an hour. The version moves in the cache store now, and on a store that will not increment a key it does not hold yet, such as the database store, the first flush writes it.
- **A long-running `matomo:work` keeps clearing the failure counter it shares with the scheduled flush.** The memo that saves repeated deletes within one drain lasted for the life of the daemon, so after its first success the daemon never cleared the counter again, and failures of a scheduled `matomo:flush` on another process added up across healthy runs until a batch was dead-lettered on its first failure of its own. Each drain clears it once now.
- **`matomo:test` names `server_options` beside `ALTER ROLE` for the PostgreSQL timeouts it finds unset.** The warning and the documentation said Laravel's `pgsql` connector had no option for these, which stopped being true with Laravel 13.33: a connection's `server_options` sets them for that connection, with no change to the role. The PostgreSQL page shows both ways.
- **A page served as `304 Not Modified` keeps its title when the tracker sits inside `cache.headers`.** Laravel renders the page, compares the validator and drops the body, and the middleware read the title only after that, so every revalidated page was named by its route or path and the page-title report split it in two. The title is read in `handle()` now, while the page is whole. List `matomo.track` after `cache.headers` on the route; on the `web` group a revalidated page keeps its route or path.
- **`matomo:annotate --release --site=N` marks the deployment on site N.** The option was read only for a manual note, so a release marker always landed on the configured site while the command reported success.
- **The privacy partial reads a regional locale in its language.** Laravel looks a line up in the current locale and then in the fallback locale, so an application on `pt_BR`, `de_AT`, `fr_CA` or any other region rendered the paragraph in the fallback locale, usually English, although the package ships the language. The partial now reads the locale's language in between, through `MatomoAnalytics\Support\Translation::line()`, and a line you publish for the region itself is still read first. A copy of the view you published earlier keeps calling `Lang::get()`: publish it again or swap the call.
- **`matomo:forget` fails in a run that cannot ask, instead of reporting success.** Under `-n`, `-q` or `--silent` the confirmation prompt answers no on its own, and the command exited `0` after deleting nothing, so a deletion request run from a job read as done. Without `--force` such a run now deletes nothing and exits non-zero with the reason. A prompt declined by a person still exits `0`.
- **A request header that is not valid UTF-8 no longer loses the request's hits.** The referrer, the user agent and the language were handed on as the client sent them, and both the queue payload and the buffer are JSON, which refuses a broken byte: in `queue` mode every hit of such a request was lost, in `batch` mode every hit from that one on. Every string in a hit is now made valid UTF-8 before it is handed on, a broken byte replaced by `?`.
- **`queue.tries` bounds queued delivery with `resilience.never_throw` off as well.** The job threw before it looked at its attempt budget, and Laravel's worker does not count attempts while a job carries a `retryUntil()` deadline, so a failing batch was retried until `queue.retry_until_minutes` ran out, a day by default. At the last attempt the job now fails into `failed_jobs`, the way the worker ends a job at its maximum tries, and the exception still reaches your exception handler.
- **A page title made only of Unicode whitespace no longer names a page invisibly.** PHP's `trim()` removes only ASCII whitespace, so a `<title>` of `&nbsp;`, or a beacon title of no-break, zero-width or ideographic spaces, became the page name. Such a page is now named by its route or path, like one without a title. The middleware also records a title broken over several lines on one line, and the hit beacon reads its text fields the same way.
- **In `queue` mode, a batch Matomo rejects as wrong is parked on the first attempt instead of being sent again over the whole retry budget.** A refused token (401, 403), an unknown site id (400) or a URL that is not the tracking endpoint (404) gets the same answer on every attempt, and the job sent such a batch five times over more than twenty minutes before parking it, for every tracked request. Every 4xx except 408, 423, 425 and 429 now goes to the dead-letter store at once, as `batch` mode has always done, and `matomo:replay` sends it again once the configuration is corrected. Back-pressure statuses, server errors and connection failures are retried with backoff as before.
- **Rolling back the two index migrations no longer drops an index they did not create.** `add_failed_at_index_for_retention` and `add_claimed_by_index_to_tracking_buffer` add their index only where none exists over the column, and their rollback dropped whichever one was there: an index you had added yourself before they ran, under any name, went with it. Both now keep the index on rollback. An index holds no data, and the table it belongs to is dropped by its own migration's rollback.
- **A blank `MATOMO_BATCH_SIZE=`, `MATOMO_BATCH_INTERVAL=` or `MATOMO_DEAD_LETTER_RETENTION_DAYS=` leaves the shipped number in the config.** An empty `.env` line reads as `''`, which the default of `env()` does not replace, so the config held `''` where it promises a number. The package's own readers already took `''` for the default, so delivery and the daily dead-letter prune were not affected; what changes is the value your own code, a published copy of the file and `config:show` see.
- **In `sync` mode, a failed send fires `TrackingFailed`.** The event means a delivery will not be attempted again, and a failed send in `sync` mode is exactly that, but it fired in `queue` and `batch` mode only, so a listener wired as the alarm stayed silent. The configuration and the reporter now also say what they did all along: `report_after_attempts` holds back the report of a delivery that is retried, and a failure that is never retried, such as a `sync` send, is reported at once.
- **The dead-letter migration names its own setting when the table name is too long.** It told you to rename the table through `matomo-analytics.batch.table`, which is the buffer table's setting, so following it changed the wrong table and the migration failed again. It names `matomo-analytics.batch.dead_letter.table` now.
- **A cached report on the current month, week or year, on a range ending today, or on `lastN` is kept five minutes, not an hour.** The cache picked its lifetime from the `date` string alone, so `period=month&date=2026-10-01` counted as a past day and was cached for the `historical` hour, a range ending today written as a date likewise, and `last7` for the `recent` quarter hour, although Matomo has not archived any of them yet. The lifetime now follows the last day the span covers: on or after today it takes `ttl.today`, yesterday `ttl.recent`, and anything older `ttl.historical`.
- **A `queue.backoff` list without a usable number falls back to the shipped schedule.** It fell back to a single 30 seconds, which the configuration never offered and nothing documented. A value that is not a list already took the shipped `30, 120, 300, 900`, and both cases now read it from the shipped config file.

### Security

- **A Matomo API error no longer carries the request's segment into the log or the exception tracker.** Matomo quotes the segment it could not read in its error, and the segment of a GDPR request names the data subject, so a mistyped `matomo:forget` sent an e-mail or an IP address to the application log and, on the default `report` channel, to the exception tracker. The reported exception names the API and the method; the caller still reads Matomo's text from `lastError()`. Log lines of reporting, GDPR and annotation requests now start with `Matomo reporting request failed:`, `Matomo GDPR request failed:` and `Matomo annotation request failed:` instead of `Matomo tracking failed:`.
- **The rate limit of the two beacon endpoints counts an IPv6 client per /64.** A connection is given at least a /64 and every device in it picks its own address, so a limit counted per full address gave a sender a fresh counter each time it changed its address, out of 2^64. An IPv6 address now counts under its /64, and an IPv4 address, also one written as IPv6 (`::ffff:192.0.2.1`), under itself. The address sent to Matomo is unchanged.

### Documentation

- **The GDPR page states how long a dead letter really stays in the database.** It said up to thirty days. The retention is `batch.dead_letter.retention_days`, 30 days by default and without limit at `0`, and the daily prune runs only while the scheduler does.
- **The scheduling note in the config and on the commands page names the Laravel version it holds for.** A foreground task's non-zero exit reaches `ScheduledTaskFailed` and your exception handler from Laravel 12.11 on; before that it shows only in the output of `schedule:run`. The commands page now lists `onFailure()` attached through `configureSchedule()` as the route that sees a failed run on every supported version, in either mode.

## [0.31.1] - 2026-10-03

### Fixed

- **`laravel/ai` is no longer listed under `suggest`.** Nothing in the package uses it at runtime: it serves this repository's own tests and stays a development dependency. The entry for 0.30.0 said the reverse, that it had moved from the development dependencies to `suggest`, and the suggestion shipped on the package page with that reason.
- **A zero or negative number in one of seven `MATOMO_*` limits falls back to its default instead of taking effect.** `MATOMO_TIMEOUT`, `MATOMO_CONNECT_TIMEOUT`, `MATOMO_REPORTING_TIMEOUT`, `MATOMO_BATCH_MAX_PER_FLUSH`, `MATOMO_BATCH_MAX_ATTEMPTS`, `MATOMO_REPORT_AFTER_ATTEMPTS` and `MATOMO_REPORT_THROTTLE_MINUTES` took `0` and `-3` as set, and Laravel's HTTP client hands a timeout of `0` to Guzzle, where it means waiting without any limit. Each of them is read with a floor of `1` now, and a value below it lands on the shipped default, the way a value that is not a number already did.
- **The Boost skill names all five publish tags.** It listed `matomo-analytics` and `matomo-analytics-config` only, so an agent did not learn that the migrations, the privacy-policy view and the translations can each be published on their own, with `matomo-analytics-migrations`, `matomo-analytics-views` and `matomo-analytics-lang`.

## [0.31.0] - 2026-09-26

### Fixed

- **On a Redis server older than 6.2, the `redis` batch buffer reports that it cannot flush instead of reporting an empty buffer.** It moves hits with `LMOVE`, which such a server does not know, and the client reads the server's error the way it reads an empty list: every flush reported nothing delivered with exit 0 while the queue grew without bound. When a claim finds nothing although the queue holds hits, the buffer now asks the server whether it knows `LMOVE`, and a server that does not ends the flush as unavailable, with the missing command in the report.
- **`php artisan migrate` no longer stops at the `jsonb` migration on a PostgreSQL connection with a table prefix.** The migration looked for the buffer table through the schema builder, which adds the connection's `prefix`, and then altered it under its bare name, which such an install does not have: PostgreSQL refused with "relation does not exist", and every later `migrate` stopped at the same migration. It now alters the prefixed table.
- **GDPR erasure no longer runs out of memory on a large buffer or dead-letter table.** It read both tables through a cursor, and PDO holds the complete result of such a query in memory before the first row arrives. The erasure runs when these tables are at their largest, after an outage with the whole backlog in the buffer, and could stop half way through. Both tables are now read in pages of 1,000 rows.
- **`php artisan matomo:test` says when it cannot read the buffer or the dead-letter store.** Both counts treated a store that did not answer as empty, so with the buffer's Redis down the command printed "Matomo OK" and nothing about the buffer, while every hit the application tracked failed to reach it. It now prints which store could not be read and why, with any password in a connection URL masked. The exit code is unchanged: the connection test itself still passes or fails on its own.
- **Hits from queued jobs and Artisan commands no longer share one invented visitor.** A console process has no visitor, and Laravel binds a request for it built from `app.url`, with `Symfony` as the user agent and `127.0.0.1` as the address. Every job and command hit carried both and one visitor id derived from them, so Matomo merged them into one visitor on localhost, and with `127.0.0.1` in `except_ips` the gate dropped them all. Such a hit now goes out without a visitor id, user agent, language or address, and `except_ips` does not match the invented address. To attribute a job's hit to a person, pass their visitor id through `CustomParameters`; the server-side tracking guide shows how.
- **Reporting a failure can no longer throw into your application.** The report of a failed hand-off is throttled through the cache, and when the queue and the cache share one Redis or one database, the throttle failed along with the dispatch: that second exception escaped the tracking layer and reached the uncaught-exception handler on every tracked request. The throttle now lets the report through when it cannot be asked, a `resilience.reporting.level` that no logger accepts falls back to `warning`, and a failure of the log or of your exception handler while reporting ends in the reporter.
- **Server-side hits leave Octane workers and queue workers again.** The hits of a request or a job are collected and handed off when it ends, and the hand-off asked the application the package was booted in. Octane serves each request from a clone of the application, so the request's collected hits were never handed off, and in the default `queue` mode no server-side hit left an Octane worker. A queue worker resets its scoped services before every job, so a job's hits were dropped with them, except the last job's before the process ended. The hand-off now reads the application it runs in, and a queue worker hands a job's hits off as the job finishes.
- **In `queue` mode on a `sync`, `deferred` or `background` queue connection, a failed batch is dead-lettered instead of dropped.** Those connections run a job inline and once, so the retry the job asked for never happened: the batch was gone with no dead letter, no event and no report. Such a batch is now parked at once and announced like one that ran out of attempts. `matomo:replay` on such a connection no longer loses a batch whose replay fails again: it stays parked, and the replay reads only the entries that were parked when it started.
- **Tracking works on a PHP without the curl extension.** The sender reuses one connection through Guzzle's curl handler, and it created that handler whether or not curl was there. Neither this package nor Guzzle requires curl, so such a PHP installed the package without complaint and failed on every hit. Without curl the sender now leaves the handler to Guzzle, which sends over PHP streams, one connection per request.
- **Without the dead-letter table, a batch Matomo refuses no longer blocks a flush a thousand times over.** Such a batch cannot be parked, so it goes back to the head of the buffer, and the flush claimed it again in the same run: the same request went out once per round until `max_per_flush`, nothing behind it was sent, and the run reported a thousand dead letters that were never written. The run now ends on that batch, reports that the dead-letter store could not take it, and `matomo:flush` names the missing table as the reason the buffer is stuck.
- **The `database` batch buffer no longer deletes claimed hits unsent when the connection has a read replica.** A claim marks its rows and then reads them back, and Laravel sends that read to the `read` connection unless `sticky` is set. On a split without `sticky`, the replica had not seen the claim yet, the batch came back empty, and the flusher deleted the claimed rows as if it had delivered them. Both reads of a claim now go to the write connection. A claim that still reads nothing back is released for a later run, and the run is reported as unable to read the buffer.
- **The `file` batch buffer no longer loses a hit written while a flush claims a batch.** A claim renamed the queue file aside, and a writer opens the file before it waits for the lock. A writer caught between the two appended its hit to the renamed file after the claim had read it, and the hit was deleted with the delivered batch, with nothing logged. A claim now takes the writers' lock, moves its batch into a file of its own and shifts the rest of the queue to the front, so the queue file never moves. A queue file that exists but cannot be opened is refused instead of being reported empty.
- **Without `MATOMO_TOKEN`, a hit keeps the time it happened.** The hit time (`cdt`) went out only with a token, on the assumption that Matomo accepts it from no other request. Matomo accepts it without a token while it is less than a day old, so in the default `queue` mode and in `batch` mode every hit was recorded when it was delivered, and one queued shortly before midnight landed on the next day. The time now goes with every hit. A hit delivered more than a day late, after a long retry or a dead-letter replay, is sent without it when there is no token, since Matomo would refuse the request, and is recorded when it arrives, as before.
- **The Boost skill no longer tells an agent that the tracking gate handles consent, or that tracking needs no token.** The gate refuses for environments, bots, Do-Not-Track and the opt-out cookie; consent it never asks about, since `privacy.consent` drives the JavaScript tracker only. An agent that followed the skill left server-side hits without a consent check. The skill now says to plug that check into `tracking.gate`, and that without `MATOMO_TOKEN` every server-side hit carries your server's address instead of the visitor's.

### Security

- **A blank `MATOMO_WEB_VITALS_THROTTLE=` or `MATOMO_PREFETCH_BEACON_THROTTLE=` no longer switches the beacons' rate limit off.** A `.env` line left empty reads as `''`, which the default of `env()` does not replace, and the reader took every value that was not a non-empty string for the documented `null`: a blank line, `true`, `false` or a count written as a number removed the limit from both unauthenticated endpoints. Only `null` or `'off'` switches it off now; a number is that many requests a minute, and anything else keeps the shipped `60,1`.
- **`matomo:load-sim` no longer deletes your visitors' waiting hits or sends its own to Matomo.** Without `--driver` it ran on the configured buffer: it drained the hits waiting there into the fake sender, which discards them, counted them as delivered, and a scheduled `matomo:flush` running meanwhile sent the synthetic hits to your Matomo. Each successful simulated flush also reset the application's count of failed flushes. The simulator now runs every driver in a store of its own (the default is a buffer in memory; `file`, `redis` and `database` get their own directory, key and tables), keeps its own failure count and dead letters, removes what it leaves, and puts back the configuration it changed.
- **A token in the URL fragment is redacted like one in the query.** OAuth, OpenID Connect and magic-link flows put their token after the `#` (`#access_token=…&token_type=bearer`), and since the page-view and Web Vitals beacons send `location.href`, the fragment reaches the server. The redactor returned any URL without a `?` unchanged and matched a parameter only after `?` or `&`, so `…/welcome#access_token=eyJ…` went to Matomo whole although `access_token` is on the default list. The fragment is now read like the query; scheme, host and path stay untouched.
- **`MatomoReports::bulk()` no longer lets a request choose another site.** A sub-request took the caller's parameters after `idSite`, so `bulk([['method' => 'VisitsSummary.get', 'idSite' => 999]])` returned site 999's report, with a token that usually sees every site of the instance, while `get()` has always ignored such an `idSite`. Every sub-request now carries the configured site, and a `module`, `format` or `token_auth` in it is dropped.
- **With `anonymize_ip` on, a value that is not an address is no longer sent whole.** The anonymizer handed back anything it could not mask, so text that reached it went to Matomo as `cip` unchanged, together with any address it carried, under the setting that promises the full address never leaves your server. Such a value is now left out, and Matomo records no address for the hit. The standard `Forwarded` header is read by its `for` parameter as well: set as `ip_header`, it named no address before, so every visitor behind such a proxy was tracked, and checked against `except_ips`, with the request IP instead.
- **`matomo:forget` reports Matomo's erasure and this application's apart, and fails when the local half could not run.** It summed both into one line, counted the local keys as storage areas and a `true` among them as a record, and printed the same success line when the local half had not searched at all because the segment was neither `userId==…` nor `visitIp==…`. The command now prints a line for Matomo and one for the local stores, exits with `1` and says what to do when the segment could not be evaluated locally, and names a queue or a bound buffer it could not search. A `GdprClient` of your own that answers without the local keys gets a line saying that it does not report whether it searched the local stores. `MatomoGdpr::fake()` returns the same shape as the real client, and `stubLocal()` sets the local half.
- **`matomo:forget` erases this application's copies even when Matomo matched nothing.** The hits that exist only here are the ones Matomo never received, waiting in the buffer or parked as dead letters. For such a person Matomo finds nothing, and the command stopped with "No data subjects matched" before the local half ran. It now asks whether to erase the local copies and, confirmed or with `--force`, erases them. `--export` still stops at no match.
- **GDPR erasure removes the local copies before it asks Matomo.** A flush that ran between the two halves delivered the person's buffered hits to Matomo after Matomo had erased them, and they stayed there. The local half now runs first and Matomo is asked afterwards, so whatever a flush delivered in the meantime is found and erased with the rest.
- **GDPR erasure searches the buffer your application actually writes to, and says what it could not search.** The local half read the `database` buffer's table whatever `batch.driver` was set to, so with the `redis` or `file` buffer it reported nothing found without having looked, and hits in a claimed batch came back when the batch was released. It now erases through the buffer your application uses, claimed batches included. `forget()` returns two more keys: `local_buffer_searched` is `false` when your application bound a buffer of its own that does not implement the new `ErasableHitBuffer` contract, and `local_queue_searched` is `false` in the default `queue` mode, whose queued jobs and `failed_jobs` cannot be searched.
- **The local half of a GDPR erasure reads the segment the way Matomo does, so both erase the same person.** Matomo usually decodes a segment value three times, the whole definition, then the condition, then the value, and each pass turns `+` into a space; the local purge decoded it once and kept the `+`. `userId==John+Doe` erased `John Doe` in Matomo and nobody locally, and `userId==alice%25252Bnews%252540example.com`, which is how Matomo is told `alice+news@example.com`, left that person's local copies in the buffer and the dead letters. The segment is now read with Matomo's own steps, including its choice between the definition as given and its decoded form, and a definition Matomo would read as two conditions is refused locally even when its separators are encoded.
- **A value in a `Segment` can no longer add a condition of its own.** `Segment::where()` wrote values into the definition as they came, and Matomo splits a definition at `,` and `;` before it decodes a value: `Segment::where('userId', '==', 'a,userId!=a')` became `userId==a,userId!=a`, which matches every visitor, so a report narrowed to one user or tenant by a value from a request returned everybody's rows. A `+`, a `%` or a line break reached Matomo changed as well, `a+b@example.com` as `a b@example.com`. Every value is now encoded once for each of the three times Matomo decodes it, and matches exactly as given. **Pass values as they are**: one you URL-encoded yourself is now matched literally. A dimension that holds a character of the segment syntax throws an `InvalidArgumentException`.
- **`matomo:forget` and `MatomoGdpr::forget()` now erase the visits in Matomo, and `--export` returns them.** Matomo's lookup answers with the columns `idSite` and `idVisit`, and the package read `idsite` and `idvisit`, so against a real Matomo it recognized no visit: nothing was deleted in Matomo, the export came back empty, and the command still reported the erasure. Both spellings are now read.
- **The Web Vitals and page-view beacons refuse a request another site sent.** Both endpoints take a CSRF-free POST, and their protection compared the `url` field with the application's origin. The page that sends the beacon writes that field, so a foreign page could make its visitors post `url=<your app>/admin`, and scheme, host and port all matched: invented page views and measurements landed under any of your pages, with each visitor's real address and user agent. The endpoints now check what the sending page cannot write: the request's `Origin` header must be your application's origin, or, without one, `Sec-Fetch-Site` must be `same-origin`. Anything else gets `403` and records nothing. Both features are off by default.
- **`ip_header` is read from the right, so a client can no longer choose its own address.** A proxy appends the address it received a request from to whatever `X-Forwarded-For` the request already carried, and the package read the first entry, the one the client writes. Every request with an invented first entry got a rate-limit bucket of its own on the Web Vitals and page-view beacons, and the same choice set `cip`, `except_ips` and the cookieless visitor id. The client is now the entry the trusted proxy appended: the last one behind a single proxy, or the one `ip_header_trusted_hops` names (new, `MATOMO_IP_HEADER_TRUSTED_HOPS`, default `1`) behind a CDN and a load balancer that both append. A header that names no address is treated as absent instead of being passed on as text. **If you run two appending proxies in front of the application, set `ip_header_trusted_hops` to `2`,** or the address of the outer proxy is used for every visitor.
- **A `privacy.consent` value the tracker does not know now asks for consent instead of switching it off.** The setting has three values, `none`, `cookie` and `full`, and every other value used to push no consent call at all, so a typo such as `'Full'` or `'required'`, or a boolean, tracked every visitor without asking. Such a value is now read as `full`, and `php artisan matomo:test` names it, because the tracker then records nothing until your consent layer grants consent.

## [0.30.0] - 2026-09-19

### Changed

- **Nine hardening keys can be set from the environment, and every numeric one is validated before it is trusted.** `batch.max_per_flush`, `resilience.connect_timeout`, `resilience.reporting.report_after_attempts`, `resilience.reporting.throttle_minutes` and the two public-endpoint rate limits (`web_vitals.throttle`, `prefetch_beacon.throttle`) were reachable only by publishing the config — and publishing freezes every OTHER default in the same file, including one this package tightens later. They now read `MATOMO_BATCH_MAX_PER_FLUSH`, `MATOMO_CONNECT_TIMEOUT`, `MATOMO_REPORT_AFTER_ATTEMPTS`, `MATOMO_REPORT_THROTTLE_MINUTES`, `MATOMO_WEB_VITALS_THROTTLE` and `MATOMO_PREFETCH_BEACON_THROTTLE`. The numeric keys — including `timeout`, `reporting.timeout` and `batch.max_attempts`, which already read the environment — go through `filter_var(…, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE)` and fall back to the shipped default, never to `0`: a cast would turn a typo in `.env` into a **zero** ceiling, which for most of these means no ceiling at all. Every default is unchanged, so an installation that sets none of them behaves exactly as before.
- **The dev toolchain carries `laravel/mcp` and `laravel/boost`.** They sit in `require-dev`, so nothing a consumer installs, calls or configures changes, and a Renovate rule keeps their constraints following each release. `laravel/ai` was briefly there too and is a `suggest` instead: it is only ever called by a judge-backed eval, this package writes none, and it requires `aws/aws-sdk-php` for a provider nothing here calls — 67 MB and 3512 files in every checkout and every CI lane. Still nothing a consumer installs either way; the entry is corrected rather than followed by a second one, because both movements land in the same unreleased section and a reader should not have to net them out.

### Fixed

- **The bug-report form no longer tells a Laravel 12 user they are unsupported.** Its checkbox asked a reporter to confirm "PHP ^8.4, Laravel ^13" while `composer.json` has declared `illuminate/support: ^12.0 || ^13.0` throughout. The form ships to the public mirror and is where every bug report starts, so the first thing a reporter on the older major read was that this package excludes them -- and it never did. It now names both majors, and a new arm holds the form and the manifest to each other, so raising or widening either floor cannot leave the form behind again.

## [0.29.1] - 2026-09-15

### Changed

- **Two comments in `ReportClient` are reworded.** Nothing a consumer calls, receives or configures changes.
- **The AI crawler list recognizes two more bots from ai.robots.txt:** `InstantAIGuruBot` and `ZipchatBot`. No entry was dropped, and neither name appears inside a common browser's user agent, so no human visit is counted as a bot.

## [0.29.0] - 2026-09-13

### Added

- **A page the browser served out of a Speculation Rules prefetch can report itself.** Skipping the prefetch alone would trade a page view too many for a page view missing: when the reader does click, the browser renders the page from bytes it already holds and the server hears nothing. Opt into `prefetch_beacon` and `@matomoPrefetchPageView` renders a small inline script that asks the browser where the document came from and beacons a page view **only** when the answer is `navigational-prefetch` — an ordinary load has already been counted server-side, and an engine that does not report `deliveryType` sends nothing. The endpoint accepts only a URL on your own origin, bounds the title, falls back to the path when none is sent, and runs the hit through the normal gate; it is throttled on the visitor's real address and answers 404 while the feature is off. The directive takes a CSP nonce like the others.

### Fixed

- **A Speculation Rules prefetch no longer counts as a page view.** `TrackPageViews` skipped a `wire:navigate` prefetch but not one from `<script type="speculationrules">`, which is an ordinary GET with a full 200 HTML body — so with `eagerness: moderate`, where Chrome fetches as soon as the pointer rests on a link, a reader hovering a menu counted a view for every link they passed over. A request whose `Sec-Purpose` or `Purpose` header carries `prefetch` is now skipped; the match is on the token, so a prerender (`prefetch;prerender`) is covered too. `middleware.skip_prefetch` switches it back off.

## [0.28.8] - 2026-09-13

### Fixed

- **The scheduled flush and dead-letter prune run once per tick, not once per server.** Both were guarded against overlapping runs but not against a second application server: the overlap lock is released as soon as a run finishes, so a flush done on one node let the next node flush the same buffer in the same minute, and the daily prune ran once per node. Both events now carry `onOneServer()`, which takes a lock per event and tick. That lock needs a cache store shared between your servers; on `file` or `array` every server takes its own, and nothing reports it. A `configureSchedule()` callback can still switch it off.

## [0.28.7] - 2026-09-11

### Changed

- **The buffered payload is stored as `jsonb` on PostgreSQL.** Run `php artisan migrate` after updating; a migration retypes the column on an existing install, and it does nothing on MySQL or SQLite.

  The payload is a map of Matomo tracking parameters. The array cast writes it and reads it back, and the flush rebuilds a query string out of the array — nothing reads its bytes, compares two encodings of the same map, or depends on key order, which is the one thing `json` can do that `jsonb` cannot. `jsonb` parses once on write instead of on every read, stores smaller, and is the type a containment or key lookup could use. It is also what every consumer's database audit asks for: SQLens reports the column as `PG.L6.JSON_NOT_JSONB` in each of their reports.

  The migration refuses a configured `batch.table` that is not a bare identifier, and it does so BEFORE it checks whether the table exists. That order is the whole of the guard: the other way round `Schema::hasTable()` answers false for any name that is not a real table, so a hostile one takes the quiet exit and the refusal can never run. Nothing changes for a configured name that is an identifier, which is every name Laravel can create a table under.

  **Two findings from the same audit are decisions and stay**, now with an arm each so that changing them has to be deliberate. `claimed_at`, `created_at` and `failed_at` are `timestamp without time zone` because MySQL's `TIMESTAMP` shifts with the session time zone and ends in 2038, and `timestamptz` has no MySQL counterpart — a shared definition would hand MySQL that defect back. `id` is `$table->id()`, Laravel's own serial: an identity column is the better PostgreSQL default, and adopting it here would make this package's tables differ from every other table in the application for a property no read path touches.

## [0.28.6] - 2026-09-10

### Changed

- **The manifest now declares the four PHP extensions the shipped code calls** — `ext-ctype`, `ext-filter`, `ext-hash` and `ext-mbstring`. Nothing about the code changed.

  **Nothing changes for an install that already worked, and that is worth stating plainly rather than leaving you to check:** all four were already required transitively by this package's own direct dependencies — `ext-ctype` through `illuminate/support`, `ext-filter` through `illuminate/http`, `illuminate/routing` and `illuminate/support`, `ext-hash` through `illuminate/auth`, `illuminate/cookie` and `illuminate/routing`, and `ext-mbstring` through `illuminate/console` and `illuminate/support`. Every PHP that could resolve this package therefore already had them.

  What changes is where the requirement is written. A transitive guarantee is a property of somebody else's manifest: nothing that reads ours can see it, `composer check-platform-reqs` included, and it can be narrowed upstream without a signal here. A contract test now holds both directions, so a declared extension cannot outlive its call site either.

## [0.28.5] - 2026-09-09

### Fixed

- The delivery documentation worked from a `batch.size` of 50 in two capacity figures; the
  shipped default is 200. One of them sat 61 lines below the correct block on the same page.
  It is a figure carried into a calculation rather than looked up, so a plan built on it came
  out a factor of four short and looked plausible.
- The `opt_out` configuration example omitted its `privacy` level. A missing key falls back to
  the shipped default rather than failing, so following that example produced a configuration
  that looked set while consent went on being ignored.

## [0.28.4] - 2026-09-08

### Changed

- **`matomo:work` pauses through Laravel's `Sleep` helper instead of calling `sleep()`.** In production it is the same call. What it buys is that the interval between flushes became an assertable value rather than an elapsed one: the shipped default of 60 seconds had no test that could reach it, because telling 60 from 59 meant waiting a real second, and every arm in the suite therefore set `flush_interval` explicitly and read past the default. It is also useful on your side — `Sleep::fake()` in your own tests now intercepts the worker's pause, so a test that drives `matomo:work` no longer has to wait through it. That is the whole of what this version changes for an installed package. Everything else that moved since 0.28.3 is repository maintenance — test coverage, working notes — and none of it is installed or shipped.

## [0.28.3] - 2026-09-07

### Changed

- **The bundled AI-crawler list is refreshed, 164 tokens to 172.** The new entries are `Diffbot-User`, `DoubaoBot`, `ERNIEBot`, `Kimi-SearchBot`, `MistralAI-Index`, `MistralAI-Training`, `OAI-AdsBot` and `QwenBot`; nothing was removed. Two of them are the same vendor splitting one agent into separate index and training identities, which is worth knowing if you allow one and not the other. Traffic from these agents was being counted as ordinary visits and no longer is, so a site with meaningful AI-crawler traffic will see a small drop in visit counts rather than a change in behavior.

### Fixed

- **Core Web Vitals collection works again under `php artisan route:cache`, which is to say in production.** The named rate limiter the beacon endpoint throttles on was registered inside `routes/matomo-analytics.php`, and `loadRoutesFrom()` is a bare `require` that Laravel skips outright once the route table has been compiled — so in any deploy that caches its routes the registration never ran, while the compiled table went on carrying the `throttle:` middleware that names it. Every beacon answered 500 with `MissingRateLimiterException`, and the measurement in it was discarded. One consuming site logged 334 occurrences in the hours after its deploy; every LCP and CLS it collected in that window is gone, and its error page filled with identical entries of the kind a real outage disappears into. Registration moves to `MatomoAnalyticsServiceProvider::boot()`, which runs whether routes are cached or not; the `throttle:` attachment stays in the routes file, where being compiled into the table is exactly what makes it survive. It is registered unconditionally now and reads its configuration per request rather than at boot, because the route table and the provider are compiled at two different moments: an installation that switches `web_vitals.throttle` off — or on — without rebuilding the route cache would otherwise reopen the same 500 from the other side. An opt-out answers an unlimited limit instead. Everything on 0.27.0 through 0.28.2 is affected; 0.26.x and earlier are not, because they used the inline `throttle:<max>,<minutes>` form, which carries its parameters in the compiled string and has no registration to lose. The switch to a named limiter that introduced this is still right and stays — only a named limiter can key on the address this package resolves itself, rather than on `$request->ip()`, which behind a CDN is the proxy and puts every visitor in one bucket.

## [0.28.2] - 2026-09-06

### Fixed

- `TrackPageViews` counts a `304 Not Modified` again. `only_successful` was implemented as `Response::isSuccessful()`, which is strictly 200-299, so a 304 was dropped — and a 304 is a delivered page: the reader has it, the server only declined to resend the bytes. On any site with cache validators that is the second and every later view of a page, so return visits stopped being counted at all. The hole only became reachable in 0.27.0, when `TrackPageViews` moved its tracking to `terminate()`: before that a consuming application could order an ETag middleware behind the tracker so it still saw the untouched 200, and `terminate()` runs after the whole stack, where the status is always final. A redirect is still not counted, deliberately — it delivers no page, and the page it lands on is tracked on its own request, so counting both would record two page views for one page. `TrackSiteSearch` keeps its own wider rule for the opposite reason: a search happened whatever the response rendered, including a redirect straight to a single hit. Both edges now have arms, so the difference between the two middlewares cannot be flattened by mistake. The shipped `config/matomo-analytics.php` comment and two documentation tables said "2xx only" and have been corrected.

## [0.28.1] - 2026-09-06

### Fixed

- Both `@matomoScript` and `@matomoWebVitals` now mark their `<script>` tags `data-navigate-once`, so a client-side navigation no longer re-runs them. Livewire's navigate plugin re-executes every script in the body it swaps in, and both snippets register listeners on `document`, which survives that swap — so each hop left the previous registration in place and added another beside it. Measured in a consuming application at 11 listeners added and 0 removed per navigation, against two other libraries on the same page that tore down cleanly. For Web Vitals the consequence reached the data: with N sets of observers on a metric, N beacons were sent for it, so an application's published Core Web Vitals scaled with how deeply a session had browsed — silently, and biased upward, because deep sessions weighed more than shallow ones. For the tracker it meant another `matomo.js` insert and another set of `_paq` configuration commands per hop. Livewire hashes the tag to decide whether it has run it, and exempts `nonce` from that hash, so a per-request CSP nonce does not defeat the marker; where a tag genuinely differs between two pages the hash differs too and it runs again, which is the behavior that was wanted there anyway. Outside Livewire the attribute is inert.

## [0.28.0] - 2026-09-05

### Added

- `matomo-analytics.site_id_resolver` names the site per hit, for applications that track each tenant into its own Matomo site. It is `fn(): ?int` — an invokable class-string or a closure — and it takes no arguments, because the tenant is a property of your own request context. A buffered batch may therefore carry hits for several sites, which Matomo's Bulk endpoint accepts: each buffered hit already holds its own `idsite`. That is why this is a resolver rather than a per-request connection — rebuilding the connection would not have fixed the buffer. Anything other than a positive int falls back to `site_id`, including a resolver that throws, because an extension point that can break tracking is worse than none.
- `matomo-analytics.require_tls` (`MATOMO_REQUIRE_TLS`, default `false`) refuses to track over a plaintext host. With it on, an `http://` host counts as unconfigured and nothing is tracked — the same no-op path a missing host already takes, never an exception, because this package does not throw into application code and a security setting is the last place to start. It is off by default: Matomo on a private network without TLS is a legitimate deployment, and defaulting to on would silently stop tracking for every one of them. What it protects is concrete — `token_auth` travels in the request body on every server-side hit, so an admin-capable credential crosses the network in clear text. `matomo:test` names this refusal separately from a missing host, because the two need opposite reactions.
- `SendHitsJob` now carries Horizon tags. Queued batches show up as `matomo` — and as `matomo:site-<id>` where a site id is configured — instead of disappearing into Horizon's `default` group, where the package's jobs were only ever visible as a share of a number nobody could attribute. Horizon reads the method and every other queue driver ignores it, so nothing changes for an application that does not run it. The tag set is deliberately bounded to one entry per configured site: anything per-batch would mint a new tag on every dispatch, and Horizon indexes them.

## [0.27.2] - 2026-09-05

### Fixed

- The documentation described two things the package does not do. The batch section showed `batch.size` defaulting to `50`, which it has not since 0.27.0 — draining 2000 hits takes 1021ms at 50 against 276ms at the shipped 200, so a config copied from that page pinned the slower setting. And the schema reference named `claimed_at`, `created_at` and `failed_at` as `timestamp` columns, which 0.27.0 converted to `datetime`: on MySQL a `TIMESTAMP` is converted from the session time zone, so a worker in another zone reads every open claim an hour off and a batch still being sent gets reclaimed and counted twice, and the type additionally ends in 2038. The reason now stands beside the schema table rather than only inside a migration.

## [0.27.1] - 2026-09-05

### Changed

- Nothing in the installed package. Every file this package installs is byte-identical to `v0.27.0` — measured, and the only difference in the published tree is this changelog entry itself. Everything else that changed since then is repository maintenance that never leaves the private repo. Consumers on `v0.27.0` gain nothing by upgrading, and the version exists so the tag history stays continuous instead of skipping a number.

## [0.27.0] - 2026-09-05

### Added

- **`guzzlehttp/guzzle` and `nesbot/carbon` are declared dependencies.** Guzzle is the stronger of the two: `HttpSender` carries a real `use GuzzleHttp\Handler\CurlHandler;` and constructs it on the default send path, on every hit. Nothing broke, because `illuminate/http` has carried `guzzlehttp/guzzle ^7.8.2` in every 12.x and 13.x release — but the day it stops, the first `send()` fatals. Carbon is the `symfony/console` shape: `format()` and `toDateString()` are declared on `Carbon\Carbon` and reached through the Date facade, with no `use Carbon\…` anywhere. Both are widenings floored at the first release of each supported major, and no installation that resolves today resolves differently.
- **The compatibility harness has a third leg that installs the FLOOR.** Nothing ever did. The test suite resolves the newest of everything the development toolchain allows; a `--prefer-lowest` run over a manifest that includes `require-dev` cannot reach Laravel 12 or Symfony 7 at all, because the test runner's own dependencies want `symfony/process ^8.1` where Laravel 12 wants `^7.2`; and the existing Laravel 12 leg resolves `--prefer-stable`, so it lands on the newest 12.x. `tools/compat-check.sh floor` builds a consumer manifest with no dev toolchain in it and resolves downward — `symfony/console` reaches 7.2.0 there, where every other measurement lands on 7.4. It runs as its own matrix leg on every change to the shipped tree.

  Two things it measured on its first run are written into the script rather than left to be re-derived: composer refuses `laravel/framework` v12.0.0 outright because four security advisories affect it, so the declared floor and the *installable* floor are different versions; and `compat-check.php` could exit `0` having run no check at all, which is now refused by a control in the runner that can see the whole run.
- **`MatomoAnalyticsServiceProvider::configureSchedule()` hands you each scheduled event before it is registered.** The package registers `matomo:flush` and the dead-letter prune itself, so everything Laravel offers for a scheduled task — `sendOutputTo()`, `emailOutputOnFailure()`, `onFailure()`, `pingOnFailure()` — was out of reach for exactly those two. It matters most for the output: Laravel redirects a scheduled command to `$event->output` in the foreground path *and* the background path, so what `matomo:flush` prints goes to the null device either way, and `MATOMO_SCHEDULE_BACKGROUND=false` buys back the exit code but not the message. Your callback runs last, after the package's own `run_in_background` and overlap settings, so anything you set there wins.
- **`matomo:test` reports the buffer depth and the dead-letter count.** Nothing in the package exposed either: `HitBuffer::size()` had one caller in the shipped tree — the load simulator — and `matomo:flush` reports only the pass it just made, while the troubleshooting guide named "the buffer grows and never drains" as a symptom. Both lines are printed only when the number is not zero, the buffer is asked only in `batch` mode, and neither count can fail the command — a store that will not answer leaves the line out rather than replacing the connectivity answer you ran it for.

### Changed

- **`batch.size` defaults to 200 instead of 50, and the config says what it costs in both directions.** It is the round-trip knob: draining 2000 hits against a Matomo answering in 20ms took 1021ms at 50, 276ms at 200 and 125ms at 500 — the same hits over the same reused TCP connection, eight times apart. It is also the memory knob, which the old comment did not mention: a claimed batch is held at roughly 2.3 KB per hit, so 200 costs about 460 KB per flushing process against 115 KB before. Set `MATOMO_BATCH_SIZE=50` to keep the old value.

### Performance

- **The page-view and site-search middleware track in `terminate()`, after the response has been sent.** They ran behind `$next()` inside `handle()`, which reads as "afterwards" and is not — the visitor waited through the gate, the payload build and the buffer write, and in `sync` mode through the entire HTTP call to Matomo. Measured against an instance answering in 20ms: 24.08ms of request time in `sync` against 0.096ms in `queue`, a factor of 250, counted at the far end as 140 POSTs. The AI-chatbot middleware was moved for exactly this reason and carries the same note, and the site-search page justified a limitation with "because it runs after the response" while the code ran before it. The generation time reported as `pf_srv` is still measured before the response leaves, so that number is unchanged.
- **A response with no `<title>` no longer costs a scan of its whole body.** The pattern was unanchored and unbounded, so the cost was linear in the body: 49 KB → 0.0531ms, 195 KB → 0.2146ms, 977 KB → 1.0654ms, against a constant 0.0011ms when a title is present. The pages that paid are the ones least likely to have one — HTML fragments, error pages, HTMX and Turbo responses. The spec puts `<title>` in `<head>`, so the search is bounded at 64 KB.
- **URL redaction makes one pass instead of one per configured parameter.** Eighteen names ship by default, so an ordinary hit paid eighteen full scans of its URL — 36 across the two URLs a payload carries. They are one alternation now, and a URL with no `?` returns immediately, which covers most page views.
- **The consecutive-failure counter is cleared once per drain, not once per delivered batch.** A fully healthy 2000-hit flush issued 40 `DEL` commands, thirty-nine of them repeats within the same run — 57,600 consequence-free round trips per day per application on a per-minute schedule. The cross-process behavior is unchanged: the first clear in a run still runs, because it is what clears what the previous run left.
- **The reporting cache reads its version key once per request instead of once per report.** Twelve warm dashboard widgets cost 24 round trips, half of them fetching the same counter. `ReportCache` and `ConsecutiveFailures` are bound `scoped` rather than `singleton` for this: both now hold a per-run memo, and a singleton survives every request under Octane.
- **The 164 AI-crawler tokens are lowercased once per process rather than once per hit.** The lowering sat inside the match loop over a compile-time constant — 0.0035ms per hit, about 4% of `track()`. Configured lists are deliberately not memoized: they are short, and caching them would serve a stale list the moment somebody changed the configuration.

### Fixed

- **A rollback died when the index had been created by hand.** The three index migrations guard with `hasIndex($table, ['claimed_by'])`, which matches on COLUMNS whatever the index is called, and then drop with `dropIndex(['claimed_by'])`, which builds `<table>_claimed_by_index` and drops that name. The two agree only when Laravel created the index — and a DBA adding it by hand is likely, because the package ran eight releases without it. `up()` then passed cleanly and the rollback died with `SQLSTATE[42704]` on PostgreSQL or `1091` on MySQL; since the newest migration rolls back first, the whole rollback ended there. All three read the name off the connection now.
- **A table prefix of 23 characters or more left MySQL unrecoverable.** `Schema::create()` sends more than one statement and MySQL reports `supportsSchemaTransactions: false`, so a failure halfway leaves the table created, the migration unrecorded, `migrate:rollback` doing nothing, and every later `migrate` dying on `1050 Table already exists` — neither forward nor back until somebody drops it by hand. The derived index name is 42 characters plus the prefix against MySQL's 64; a `tenant_<uuid>_` prefix is about 44. The create migrations check every identifier they are about to make **before** any DDL and refuse with the name, the length and the prefix.
- **The buffer timestamps are `datetime`, not `timestamp`.** MySQL's `TIMESTAMP` shifts with the session time zone — written `12:00:00`, read back after `SET time_zone = '+05:00'` as `16:00:00`, where PostgreSQL answers `12:00:00` — and the session zone follows the operating system's, DST included. The stale-claim arithmetic is built on those columns, so after a DST change every open claim on MySQL is an hour older (a batch still being sent is reclaimed and Matomo counts it twice) or an hour younger (a dead worker's batch is never released). The same type also rejects everything from 2038-01-19 outright: `failed_at = '2039-01-01'` fails with `SQLSTATE[22007] 1292`. A migration converts existing installations; PostgreSQL and SQLite are unaffected either way, which is also why the suite could not see it.
- **An evictable Redis is reported at runtime, not only by a command nobody runs on a schedule.** The previous fix for this was a documentation section and a line in `matomo:test`; at runtime there was no guard at all, and the loss is total and silent — measured against a real Redis under `allkeys-lru`, 200,000 hits pushed, 5,358 left, `evicted_keys=1` for the whole list at once, and `push()` threw nothing. The buffer now asks once per process and reports through the resilience channel. Once, because the answer cannot change and the question is a round trip; and silently when the provider disables `CONFIG`, which is not a finding about the policy.
- **PostgreSQL's autovacuum is tuned for the buffer table, which is a queue.** The default trigger is 20% of the table plus fifty rows, so a one-million-row backlog waits for 200,050 dead tuples — measured at 5,373 blocks to claim fifty ids against 5 after a vacuum. A migration sets a 2% scale factor with a thousand-row floor. PostgreSQL only, and silent on the other engines, which need no equivalent.
- **GDPR erasure reached Matomo and left this application's own tables untouched.** The batch buffer holds one built payload per row and the dead-letter table holds whole batches for up to thirty days — `cip`, `ua`, `url`, `urlref`, `uid` — in the consuming application's database. So an operator could run `MatomoGdpr::forget()`, be handed deletion counts, report the request fulfilled, and leave the same person's address and user agent in their own tables, while the documentation said "erases every matching visit" and the database reference did not mention that these tables hold personal data at all. `forget()` now erases locally too and reports `local_buffer`, `local_dead_letters` and `local_segment_understood` alongside Matomo's counts. It understands exactly `userId==<value>` and `visitIp==<value>`: a Matomo segment is an expression Matomo evaluates against its own schema, and guessing at it here would delete somebody else's data — so anything else is reported as **not** understood, which is a different answer from "nothing matched". A dead-letter row is rewritten rather than dropped, because it holds other people's undelivered hits too.
- **A Web Vitals measurement is attributed to the page it was taken on.** The payload builder takes the URL from the request it runs in, and for a beacon that request is the ingest endpoint — so every Web Vitals event was filed under `/matomo-analytics/web-vitals` and the report never said which page was slow. The beacon sends `location.href` now, and the server accepts it **only for its own origin**: it is unauthenticated input on a public endpoint, a form-encoded cross-origin POST needs no preflight, and trusting it would let any page choose which of your URLs a poisoned measurement lands on. A foreign URL is dropped, not refused.
- **`except_routes` now reaches Web Vitals events.** It matched the request path, which for a beacon is never an excluded route: measured with `except_routes => ['admin/*']`, a page view on `/admin/customers` was denied and a measurement taken on that same page was allowed — against `web-vitals.md` promising that an excluded route produces no event, and `tracking-gate.md` promising that every hit passes one gate. The gate now matches the page a hit is **about** when the hit names one. (`track_authenticated` and `except_abilities` still see a guest there, because the route starts no session; that is documented rather than changed.)
- **The Web Vitals rate limit is keyed on the visitor rather than on the proxy.** Laravel's throttle resolves its key from the request IP, which behind a CDN is the same address for everybody unless the application configured TrustProxies — so one bucket held all visitors, and a limit meant to bound one abuser bounded the site. It is a named limiter keyed on the address this package already resolves through `ip_header`. The configured `requests,minutes` value is unchanged.
- **The redaction defaults missed the OAuth callback the documentation names as covered.** `code`, `state`, `id_token`, `jwt` and `refresh_token` were on no list, and a callback URL lands in `urlref` on the very next page view — so the authorization code reached Matomo intact. All five are redacted now.
- **A `wire:navigate` prefetch was counted as a page view.** The middleware skips Livewire requests by testing `X-Livewire`, and a prefetch sends `X-Livewire-Navigate` — a different header key, not a longer value of the same one. So it passed all three gates: it is a GET, it was not "a Livewire request" by that test, and it carries a full 200 HTML body. Livewire prefetches on mousedown for every `wire:navigate` link and after 60ms of hover with `.hover`, so an abandoned click, or the pointer crossing a hover link, produced a page view for a page nobody saw.
- **The default `except_routes` did not cover Livewire 4.** Its endpoint prefix is hashed — `/livewire-490cd34f/update` — which `livewire/*` does not match. Page views stayed covered by `only_get` and `skip_livewire`; what slipped through was every explicit `Matomo::event()` from inside a component during an update request. `livewire-*/*` is now in the defaults, with the second segment required so a page at `/livewire-tips` stays tracked.
- **`lastError()` kept a stale failure across a cache hit.** `ReportCache::remember()` returns a hit before the resolver runs, and the resolver held the only place that cleared it — so a read that SUCCEEDED left a previous error standing for as long as the entry lived, against a contract that says "null when healthy" and a documented use that is a dashboard banner. The suite could not see it: its one arm on `lastError()` made a single call, which is necessarily a miss.
- **A buffered line whose values were all non-scalar counted as a valid empty hit.** The scalar filter exists so the buffer never trusts stored bytes blindly, and dropping *every* value is the loudest corruption signal there is — but the result was `[]`, which decoding kept. Downstream the batch was not empty, so the unreadable-batch path never ran, and empty payloads went to Matomo as hits and counted as delivered. Reachable through the public `push()` contract with a nested array.
- **Bot tokens are lowercased the way the framework lowercases them.** `strtolower` is byte-wise, so a token a consumer spelled with non-ASCII capitals matched under `Str::contains` and not here. Measured across ten real user agents against the framework's own helper: nine identical, one apart. Only consumer configuration is affected — the shipped lists are ASCII — which is the worse half, because it fails by tracking a bot the operator asked to exclude.
- **`Matomo::isFake()` answered false immediately after `Matomo::fake()`.** `Facade::isFake()` tests `instanceof Illuminate\Support\Testing\Fakes\Fake`, and none of the four fakes implemented that marker. Anything branching on it behaved as though the real tracker were bound.
- **`TrackingFailed` never fired in `batch` mode, and three places said it did.** It was dispatched only from the queued job, while `config/matomo-analytics.php` — the file you publish and read — the service provider and the 0.24.0 changelog all pointed at it as the batch-mode alarm. Measured over four batch-mode failure shapes: zero dispatches. It matters more here than in the queue path, because `matomo:flush` runs in the background by default, where its exit code reaches nothing and the events *are* the channel. It now fires wherever a batch is dead-lettered, alongside `HitsDeadLettered`, and stays silent for a released batch — which is retried on the next flush and must not announce a terminal failure.
- **`matomo:work` was indistinguishable from a healthy daemon while losing every hit.** Measured with three buffered hits: Matomo answering 400 gave exit 0 and no output, Matomo answering 200 gave exit 0 and no output — byte-identical, while `matomo:flush` in the same state exited 1 with a diagnosis. It called the flusher, discarded the answer and returned success unconditionally. It now prints the delivered count when hits moved, prints the reason and exits non-zero when a pass is stuck, and stays silent on an idle pass so a one-minute cadence does not fill a log nobody reads.
- **A partly unreadable batch lost hits with every signal green.** A buffered row whose payload no longer decodes is skipped while the batch is built, and the acknowledgment then deletes every row of that claim — the skipped one included. Measured with three rows, one corrupt, Matomo answering 200: two delivered, nothing dead-lettered, no event, no log, buffer empty, dead-letter table empty. 0.24.0 fixed the all-or-nothing twin and left the partial case, which is both likelier and quieter. There is nothing to recover — a payload that will not decode cannot be sent by anyone — so the discarded count is reported.
- **A file spool that could not be written read as "drained", forever.** `claim()` renames the queue aside; when that rename fails the result was an empty batch, which is exactly how the flusher learns the buffer is empty. Measured with the spool directory at `0555`: nothing delivered, nothing dead-lettered, no log, no event, and `matomo:flush` printing `Flushed 0 Matomo hit(s).` every minute over hits still sitting in the file. A failed rename and a lost race are now told apart by whether the queue file is still there, and the first one ends the run marked unavailable.
- **Hits Matomo refused inside a `200` counted as delivered.** The sender asked `successful()` and never read the body, so the bulk endpoint's own envelope was discarded. `{"status":"error"}` in a 200 now takes the ordinary failure path, and a stated count of rejected requests is reported. Deliberately narrow: the per-entry envelope shape was read rather than measured against a live instance, so no delivery accounting hangs on it, and a body that says neither thing — an empty response, a tracking pixel, any non-JSON — behaves exactly as before.
- **The alert throttle gave one outage a new key on nearly every attempt.** The signature is `md5(class|message)`, and a Guzzle connection failure writes the elapsed time into its message — `Operation timed out after 2002 milliseconds`. Measured: three identical failures produced two throttle keys and four log records, which is the flood `throttle_minutes` exists to prevent. A number attached to a time unit is normalized out of the signature; nothing else is, because stripping every digit would fold `HTTP 400` and `HTTP 500` into one key and silence the second, different failure.
- **`anonymize_ip` did nothing behind a CDN or a load balancer, and `except_ips` fell with it.** `X-Forwarded-For` is a chain by definition — `client, proxy1, proxy2` — and the value was passed on whole. The anonymizer branches on a colon, a two-hop IPv4 chain has none, and the IPv4 path hands back anything that is not four octets: so the FULL visitor address went to Matomo while the setting was on and the documentation promised it never leaves your application. The same value made `IpUtils::checkIp('10.1.2.3, 198.51.100.7', ['10.0.0.0/8'])` false, so a team's own exclusion list quietly covered nobody. The client is now read from the front of the chain, with a port, a bracket pair and a zone id stripped for the same reason — each is written next to an address without being part of one, and each was refused by the validator and therefore left on the leaking path. A header holding no address at all is still passed on untouched. Nothing changes for a single-address header, which is what every existing test used.
- **An IPv6 address that merely ENDS in dotted-quad notation was anonymized as if it were an IPv4 address.** RFC 4291 lets any address be written that way, and the branch that handles `::ffff:192.0.2.1` asked only whether the string contained a dot. So `2001:db8::192.0.2.1` left with 112 of its 128 bits intact. The check now reads the mapped prefix off the packed address, which also makes the two spellings of one mapped address agree: `::ffff:c000:201` used to collapse to `::` where `::ffff:192.0.2.1` kept its network. A zone-bearing address (`fe80::1%eth0`) is masked now instead of being returned whole.
- **A redirect from the Matomo host no longer replays the request, and `matomo:test` says when the host is plaintext.** None of the four request builders that carry `token_auth` set a redirect policy, so Guzzle's default applied — and for a 307 or 308 that means the POST is repeated verbatim at whatever host the `Location` names. Guzzle's cross-origin stripping covers `Authorization` and `Cookie`; this token travels as a form field, so it was not covered. The token is admin-capable because the GDPR deletion path requires it. All four builders now refuse to follow a redirect. Separately, `matomo:test` warns when the configured host is `http` — a warning rather than a refusal, because Matomo on a private network without TLS is a legitimate deployment.

### Documentation

- **Nine comments in the shipped tree used British spelling.** Three stems of the `-ise` family and one agent noun sat in the source you read in `vendor/`, a line or two from the same words spelled the American way. The package documents itself in one dialect now.
- **The documented way to publish the migrations broke `migrate` permanently, and both pages said it.** `vendor:publish` rewrites the `0001_01_01_00000N_` prefix to the publish date and the migrator keys on the file name, so the copies are five *different* migrations rather than replacements: ten found instead of five, the bundled ones run, the published `create` dies with a duplicate table, and every later `migrate` dies the same way. `ignoreMigrations()` was in the shipped Boost skill and in neither of the two pages a person reads. Both now say it in the same breath as the publish command.
- **A rollback deletes undelivered hits and the whole dead-letter archive.** Both `down()` methods drop their table, which is what `down()` is for — and the schema reference described that same table as "data waiting for a decision" without saying so. The sharp edge is a fresh installation, where these migrations sit in the same batch as the application's own: a rollback meant to undo one of yours takes the archive with it.
- **The Laravel 12 half of the migration proof runs against SQLite.** It proves the migrations run on that major and nothing about the engines this package supports — and the three defects fixed above are each invisible to SQLite by construction. Written down rather than closed, because the test runner cannot be installed alongside Laravel 12.
- **What "durable" covers, and what it does not.** Persistence appeared nowhere in this package's prose: a Redis restart loses the buffer unless `appendonly` is on, measured at 5,000 hits buffered and `size()` answering 0 after a `kill -9` and a restart — indistinguishable from an idle minute. Nothing bounds the buffer's size either, so a Matomo outage grows it in the RAM of whatever instance it shares, and under the `noeviction` policy the same page recommends, that instance eventually refuses every write and takes the application's cache, sessions and queues with it. The cleanup has an unstated precondition too: something has to keep calling `claim()`. And a buffered hit is personal data at rest — visitor id, user agent, language, the full URL — on an instance that may have no authentication and may be shared. `matomo:test` now also warns when the append-only log is off.
- **The two PostgreSQL settings this package cannot set for you.** With no `lock_timeout`, the buffer write blocks for exactly as long as a lock on the table is held — measured at 22.9 seconds against a 22.9-second `ACCESS EXCLUSIVE`, with no upper bound, from a callback that runs inside the worker. Laravel's `pgsql` connector has no option for these, so they belong on the role; `matomo:test` reports what the connection actually resolved. The package deliberately opens no transaction, and the price of that is now written down: called from inside somebody else's transaction, the claim's row locks last until they commit.
- **Why the `(claimed_at, id)` index stays although PostgreSQL never uses it.** Measured over a full drain of a million rows: the primary key took 205,534 scans and that index zero, and forcing it makes `size()` slower. It is kept because the measurement is one engine's, and dropping an index on that basis is how the next regression is written.
- **The warning emoji is gone from every shipped source comment, and a guard keeps it out.** Four files opened comments with U+26A0 U+FE0F and nothing screened for it — not the leak patterns, not the debug arm, not the release content scan. Warning sign plus capitals is the house style of a private instruction file, and it was bleeding onto a surface every Packagist reader sees. It got worse before it got better: one working session took the count from 4 to 43 across 27 files, because the style is contagious and no arm objected. The prose is unchanged; only the marker is gone. The new screen is emoji ranges rather than "any non-ASCII", so the em dashes, the accented locale data and the Cyrillic user-agent example in the bot-detector comment all still pass — a rule that fired on those would be switched off within a day.
- **The release now requires `art/header.png` by name.** The repository's own instructions say the pipeline refuses to publish without it, and two other gates cite that sentence as their precedent — while the literal `header.png` appeared in the workflow and in the tests only inside comments. What was enforced was the directory plus a suffix policy, so renaming the file and leaving any other `.png` behind published green while the README's header image broke on GitHub and on Packagist.
- **The declared-dependency guard reads every vendor package, not only the `illuminate/*` ones.** This is the finding behind the Guzzle one: the guard built against exactly that defect anchored its extractor on `Illuminate\\`, so it read straight past a real `use GuzzleHttp\…` line and would have read past the next one too. The reverse arm had the same shape, filtering its declared set to `illuminate/`, so an unused Symfony or Guzzle entry would have sat in the manifest forever with every guard green. Both directions are derived now — a class is resolved through the autoloader and the package comes from the file path, so nothing has to be listed or maintained — with an explicit, short list for the two legitimate exceptions: an adapter for a `suggest`-only integration, and the shipped fakes that assert through PHPUnit.
- **The shipped config file stated the IP anonymization backwards.** It said "Matomo truncates the last octet(s) server-side"; the package truncates locally, before the hit is sent. That is the reversed answer to the one question an EU installation asks — whether the full address ever leaves your server — and it is the file `matomo:install` copies into every consumer application. The portal pages were right; the published config was the wrong half.
- **The upgrading guide covers 0.23.0 through 0.26.0.** It stopped at 0.22.0 while v0.26.0 was published, with an intro promising "see the sections below". 0.24.0 needs `php artisan migrate` and changes what `Matomo::track()` does outside a request lifecycle; 0.23.0 widened `ReportClient` from 5 to 27 methods; 0.25.0 is the release without which a Laravel 12 application could not install 0.24.0 at all. None of that was findable from the guide.
- **"Under Tag Manager the `js` settings no longer apply" was false for four of the ten.** `js.enabled` is still the master switch and turns the container off too, `js.noscript` still emits the pixel, `js.dns_prefetch` still emits the hints, and `js.host` still names the host in them. The `<noscript>` pixel is deliberate — a visitor without JavaScript never loads the container either — but it hits `matomo.php` directly rather than through it, and a reader choosing Tag Manager deserves to know that before wondering where a second channel came from. The page now names which four apply and which six do not.
- **`TrackingQueued` is documented as firing once per request in every mode.** It was described as once per hit in `batch` mode, which 0.24.0 changed. A listener written from that page as a hit counter counts requests.
- **A failed sub-request in `bulk()` is not `null` in its slot.** The `{"result":"error"}` envelope is detected on the outer response, so a per-request failure arrives as whatever Matomo put there — and an error object is still an object. The page said it would be `null` and now shows how to check.
- **The reason given for typing an `assertTracked()` callback as `Hit` was refutable**, in the docs and in the shipped Boost skill. Both claimed a narrower type throws "the first time a test tracks two different things"; the predicate short-circuits, so the callback only ever sees hits of the type asked for. The real trigger is `CustomParameters`: a decorated hit matches by its inner type and the callback receives the wrapper. A reader who checked the stated reason found it false and filed the advice as over-cautious.
- **Claims that promised more than the package delivers.** "Two invariants hold everywhere: tracking never blocks a response" — `sync` mode sends inline, deliberately, and the transmission-modes page says so two pages away. "It loses nothing" stood beside "hits disappear and every signal stays green" in the same published set. The Redis driver was called "reliable" and "durable" on the page where the driver is chosen, with no mention of the eviction precondition that page's own sibling explains. The README's bot list "updates itself"; it is refreshed weekly against the upstream catalog and arrives in a release.
- **`MATOMO_SCHEDULE_BACKGROUND` was missing from "Every environment variable"** — 28 of 29 keys were listed — and appeared nowhere in the command reference, which is where the exit-code behavior that background scheduling swallows is promised.
- **`assertTracked($type)` was only ever shown with `PageView::class`.** The other eleven hit types appear with the properties a callback can read, so asserting an order no longer means guessing a class name out of `vendor/`.
- **Four smaller overstatements.** "Every helper takes the same optional `$params` array" — on four of the twenty-two it is the second argument, and `liveCounters(['period' => 'week'])` is a `TypeError`. `toArray()` shows the request, not what Matomo received. An unsupported segment operator is a thrown `InvalidArgumentException`, which is a 500 when the operator came from a visitor. "Every method returns the tracker" — `flush()` returns `void`. And the buffer table has two indexes, not one.

## [0.26.0] - 2026-09-04

### Added

- **`schedule.run_in_background` lets you take the scheduled commands out of the background.** Both of them run there by default, unchanged, so `schedule:run` never waits on Matomo — but that costs you the failure report, and until now it could not be declined. Laravel raises a scheduled command's non-zero exit only when the event is NOT in the background, so a background one dispatches no `ScheduledTaskFailed` and never reaches your exception handler: a nightly prune that fails is invisible to Sentry, Flare or Nightwatch. Set `MATOMO_SCHEDULE_BACKGROUND=false` when that report matters more than the wait.

### Fixed

- **`symfony/console` is a declared dependency now, so a lean install cannot be missing it.** The package's nine console commands return `self::SUCCESS` and `self::FAILURE` thirty-one times, and those constants belong to Symfony's `Command` — Laravel's extends it and declares neither. The dependency has been real since the first command and was resolved only because `illuminate/console` happens to bring it. Declared at `^7.0 || ^8.0`, which is wider than what Laravel 12 (`^7.2.0`) or Laravel 13 (`^7.4.0 || ^8.0.0`) already require, so no installation that works today stops working.

## [0.25.0] - 2026-09-04

### Added

- **Laravel 12 is verified on every change, not only declared.** The package has stated
  `illuminate/* ^12.0 || ^13.0` for months while only the Laravel 13 half was ever exercised.
  Both majors are now checked against the shipped code whenever it changes: the service provider
  registers, config merging keeps nested keys, the ingest route appears with its rate limit, the
  Blade directives compile, the console commands register, the migrations run, a hit reaches the
  buffer, and the gate still refuses traffic while the master switch is off.

  The supported range itself did not change.

### Changed

- **The Redis buffer claims a batch in one round trip instead of one per hit.** Claiming worked
  by issuing a separate `LMOVE` for every hit it wanted, so a batch of forty cost forty round
  trips to Redis and paid the network latency forty times. The whole batch now goes out as a
  single pipeline and comes back as one reply set, with the previous per-hit loop kept as the
  path for a client that has no pipeline of its own. Returning hits to the queue after a failed
  flush works the same way, and asks how many there are before it does anything at all.

- **The HTTP sender reuses one connection for a whole flush run.** Each batch built its own
  request, its own handler stack and therefore its own curl handle, so a flush that walked forty
  batches opened forty connections to the same host and paid a TCP and TLS handshake for each.
  One handler is now shared across sends.

  It shares the handler rather than the client on purpose, and that distinction is load-bearing
  for anyone testing an application that uses this package: a shared client bypasses the handler
  stack that `Http::fake()` installs itself into, which would silently turn faked requests into
  real ones. A shared handler sits underneath that stub, so faking still intercepts and no socket
  is opened.


### Fixed

- **The package requires `symfony/http-foundation` and `symfony/http-kernel` at `^7.0 || ^8.0` again, so every Laravel 12 application can install it.** Both were declared in 0.24.0 at `^7.4.0 || ^8.0.0`. That is Laravel 13's own constraint, and this package also supports Laravel 12 — whose framework asks for Symfony `^7.2.0`. An application running Laravel 12 with Symfony 7.2 or 7.3 therefore could not install 0.24.0 at all, or was forced to upgrade Symfony to take it, while the README and the documentation both said "Laravel 12 or 13".

  Nothing about what the package DOES changed, and nothing in it ever needed Symfony 7.4: the four APIs it touches — `IpUtils::checkIp`, `Cookie`, `Response` and `NotFoundHttpException` — are unchanged since Symfony 7.0. The constraint was simply narrower than the promise.

- **Two compare links in this file pointed at a tag that does not exist.** The `[0.19.0]` and
  `[0.18.0]` definitions both named `v0.18.0`, and both answered 404 — in the published document,
  not only here. The 0.18.0 section says so itself nine hundred lines higher ("no `v0.18.0` tag
  exists; it was never published"), so the file contradicted its own links. Both now point at
  `v0.17.0...v0.19.0`, the range those changes actually shipped in.


## [0.24.0] - 2026-08-27

### Added

- **`symfony/http-foundation` and `symfony/http-kernel` are declared dependencies.** Both are
  named in the package's own public API — `OptOut::enable()` returns a Symfony `Cookie`, the
  middlewares type-hint its `Response`, the gate uses `IpUtils`, the controller throws
  `NotFoundHttpException` — and neither was in `require`. They resolved anyway, transitively
  through `illuminate/*`, which is exactly why nobody noticed. Same constraint the framework
  uses, so nothing about resolution changes.

- **`web_vitals.middleware`** names extra middleware for the ingest route. It is empty by
  default and stays that way, because the browser beacons that route with `sendBeacon()` and
  that carries no CSRF token. The consequence is now written down rather than left to be
  discovered: no session is started on that path, so `tracking.track_authenticated` and
  `tracking.except_abilities` see a guest there regardless of who is logged in. Set it to
  `['web']` if you need those rules to apply, and exempt the one route from CSRF on your side.

- **An index on `matomo_tracking_buffer.claimed_by`.** Three of the five buffer operations
  filter on that column — reading the payloads back after a claim, acknowledging a delivered
  batch, and releasing a failed one — and none of them had an index to use. A delivered batch
  therefore cost two full scans of the buffer table and a released one cost two more, at up to
  forty batches per flush run. That is invisible while the buffer is small and stops being
  invisible exactly when a backlog builds, which is the situation the buffer exists for.

  It ships as a new migration rather than an edit to the one that creates the table, so
  existing installations get it too. Run `php artisan migrate` after upgrading.

### Changed

- **`@matomoWebVitals` no longer blocks the parser to measure how fast your page renders.**
  The library tag carried neither `async` nor `defer`, and the inline glue read
  `window.webVitals` the instant it parsed — so it had to block, and the numbers it reported
  were worse for its own presence. The tag is `defer` now and the glue waits for
  `DOMContentLoaded` (or starts immediately if the document is already parsed, for the
  placement at the end of `<body>`). `defer` rather than `async` on purpose: deferred scripts
  run in document order, so the glue still finds the library where `async` would race it.

- **Both scheduled commands now run in the background.** They are registered on the
  **consumer's** scheduler, and without `runInBackground()` Laravel calls `finish()`
  synchronously — so `schedule:run` waited for a flush, and a flush waits on an HTTP call to
  Matomo. A slow or unreachable instance held up every other task in that minute. The
  trade-off, stated because it is real: a background event no longer throws on a non-zero exit,
  so the command's exit code stops reaching the scheduler. The `TrackingFailed` and
  `HitsDeadLettered` events are the channel for that, and they now fire from both delivery
  modes.

- **`batch` mode collects during the request and writes once at the end, as `queue` mode
  already did.** Every tracked hit used to make its own `push()` — a database `INSERT` or a
  Redis round trip depending on the driver — while the response was still being built. The two
  modes paid very different prices for the same call and only one of them had a reason to. If
  you call `Matomo::track()` outside a request lifecycle and rely on the buffer being written
  immediately, call `Matomo::flush()`; the service provider already does this on terminate.
  `TrackingQueued` now carries the request's hits in one event in `batch` mode, matching
  `queue` mode, instead of one event per hit.

- **`batch.stale_after_minutes` is floored at one minute.** It was the only batch value with no
  lower bound, and zero inverts the guarantee: every claim is expired the moment it is made, so
  the next flush reclaims a batch the current one is still sending and Matomo counts every hit
  twice. All three buffer drivers apply the floor.

- **AI-chatbot telemetry is recorded in the middleware's `terminate()`.** It ran after
  `$next()` in `handle()`, which reads as "afterwards" and is not: the fetcher is still on the
  wire while the payload is built and — in `sync` mode — while the call to Matomo completes.
  The documentation already promised that this "costs the fetcher nothing", and that sentence
  was only ever true of `terminate()`.

- **`matomo:replay` now delivers through the channel your mode actually uses.** It pushed
  every replayed hit into the buffer regardless of mode — and the buffer is only ever drained
  in `batch` mode, because the scheduled flush is not registered for anything else. On the
  shipped default (`queue`) the command deleted the dead-letter rows, filled a store nothing
  reads, and printed "Replayed N hits". The hits were gone.

  `batch` mode still goes through the buffer. `queue` mode dispatches a `SendHitsJob` per
  entry, the same way the live path does. `sync` mode sends immediately — and if that send is
  refused, the dead-letter row is **kept** and the command exits non-zero, rather than being
  deleted for a delivery that did not happen.

- **`DeadLetterStore::take()` returns a generator instead of an array.** It materialized every
  row and its decoded payload tree at once. With a realistic Matomo payload (493 bytes of JSON,
  50 payloads to a row) a decoded row costs about 86 KB against 24 KB on disk, so a two-thousand
  row backlog came to roughly 168 MB before the first hit moved. `matomo:replay` now holds one
  entry at a time. Code that calls `take()` and indexes into the result needs
  `iterator_to_array()`; `foreach` is unaffected.

- **The retention prune deletes in steps of 500 rather than in one statement.** Each row here
  carries a whole batch in a `longText`, so an unbounded `DELETE` held the table for as long as
  the outage that filled it — and on PostgreSQL left the dead tuples behind until autovacuum
  caught up. The cutoff is computed once, before the loop, so a long prune cannot widen its own
  window.

### Security

- **The Web Vitals endpoint could lose its rate limit without anyone changing it.** The route
  read `web_vitals.throttle` with an accessor that cannot tell "the operator switched this off"
  from "this key is absent" — and it was the only security-relevant read in the package with no
  shipped fallback under it. A consumer whose published config predates the key, or who trimmed
  the file to the keys they tune, ran an unauthenticated POST endpoint with no limit at all. An
  absent key now falls back to the shipped `60,1`; an explicit `null` still switches it off.

- **The measurement in a Web Vitals beacon is now bounded.** The metric name and the rating were
  both held against allowlists; the one number in the payload was checked with `is_numeric()`
  alone, which accepts any magnitude — so `1e400` cast to `INF` and an unauthenticated browser
  POST could put a non-finite value into your reports, where it poisons every average it lands
  in. Values must now be finite, non-negative, and no larger than an hour in milliseconds.

### Fixed

- **One unreadable row used to stop the whole buffer, permanently.** A batch whose payloads no
  longer decode came back with a claim and zero payloads, and the flusher's exit condition read
  that as "the buffer is drained". The rows kept their claim, went stale, were reclaimed, failed
  to decode again — and every hit behind them waited forever, with no error, no dead letter and
  no failing flush. Such a batch is now reported once and discarded, which is the only disposal
  that lets the rest of the buffer move: a payload that will not decode cannot be sent to Matomo
  by anyone, so there is nothing to dead-letter and nothing to replay.

- **`SendHitsJob::failed()` now accepts `?Throwable`, which is what the framework passes.** A
  job killed by `queue:work --timeout`, or failed through `Queue::failing()` with nothing
  attached, arrives with a null — and a non-nullable parameter turned that into a `TypeError`
  inside the worker's own failure handling, the one place an error has nowhere left to go.

- **Every version heading in this changelog now resolves.** Thirty-one of them sat in square
  brackets with no link definition anywhere in the file, so GitHub and Packagist rendered a
  literal `[0.23.0]` where a compare link belonged.

- **`matomo:flush` exits zero while losing every batch.** It reported failure only once the
  consecutive-failure counter reached the alerting threshold, and that counter has exactly one
  increment site in the package — in the *transient* branch. A wrong site id or host makes
  Matomo answer `4xx`, every batch is dead-lettered as poison, and the run ends at zero
  delivered: the same number a quiet minute produces, printed with the same line. A run that
  delivered nothing **and** lost at least one batch now says so and exits non-zero. One poison
  batch among delivered hits stays green, which is the dead-letter queue doing its job.

- **`php artisan matomo:test` now reads Redis's `maxmemory-policy`** when the `redis` buffer is
  in use, and warns on an `allkeys-*` policy. Under one of those the buffer's keys are as
  evictable as any cache entry, and an eviction looks like nothing at all: `LLEN` answers 0,
  the flush ends, and the command prints `Flushed 0 Matomo hit(s).` and exits zero. The
  reliability guide now states the precondition, and that `maxmemory-policy` is instance-wide —
  a separate logical database on the same server does not help.

- **IPv6 anonymization produced an address that was not an address.** `anonymize_ip` split the
  value on `:` and kept the first three groups, which is correct for the fully written-out form
  and for no other. Any address carrying a `::` run — the ordinary way IPv6 is written — split
  into empty elements, so `2001:db8::1` went to Matomo as `2001:db8:::`. Neither side complained:
  Matomo stored what it was sent, and the geolocation simply missed. The address is now
  normalized through `inet_pton` before the first 48 bits are kept, so every input form gives the
  same, valid result. An IPv4-mapped address (`::ffff:192.0.2.1`) is anonymized as the IPv4
  address it is, rather than collapsing to `::` along with every other mapped address.

- **`HitsDeadLettered` now fires in `queue` mode.** The event was dispatched from exactly one
  place in the package, and that place only runs in `batch` mode — so on the shipped default the
  listener this documentation recommends as the alarm for parked hits never fired, while the
  dead-letter table filled up. `TrackingFailed` still fires alongside it: one says the batch will
  not be attempted again, the other says where it went.

- **A missing dead-letter table no longer throws out of the delivery path.** Every read and write
  on the store now checks for the table, as the retention prune already did. An installation that
  calls `ignoreMigrations()` while leaving the dead-letter store switched on used to get a
  `QueryException` for "Undefined table" in place of its actual delivery error. A batch that
  cannot be parked now takes the honest route instead: the queued job fails into `failed_jobs`
  where it is visible and re-runnable, and the buffered flusher releases the batch back into the
  buffer rather than acknowledging it away.

### Documentation

- **The scaling guide now states the `redis` driver's boundary.** The buffer has no upper
  bound — `push()` is an unconditional `RPUSH` and `batch.max_per_flush` limits draining, not
  filling — so an outage turns straight into Redis memory at whatever rate your traffic
  produces hits. The counter-pressure that exists is slow by design: a batch dead-letters only
  after its full attempt window, roughly twenty-five minutes for fifty hits on the shipped
  defaults. The page says what to watch (`LLEN`) and why sizing for steady state is the wrong
  sizing.

- **The bundled Boost skill said three things the source contradicts, and one of them
  configured a package that tracks nothing.** Its setup step named two environment variables
  as "the whole minimum" and left out `MATOMO_ENABLED` — the master switch that ships off and
  that the tracking gate consults as its very first rule — while the next sentence advised
  against looking for conditionals. Boost hands this file to a coding agent inside your
  application, so it is followed rather than skimmed. It also asked for the migrations to be
  published, which the package registers itself, and it taught the `assertTracked` callback
  with a narrowed parameter type that throws as soon as a test tracks two different things.
  Four arms now hold the skill against the source instead of against a reviewer's memory.

- **A README badge disagreed with the package's own configuration.** The threshold behind it
  was raised in an earlier release and only one of the places that state it moved, so the badge
  on GitHub and Packagist showed a number the project no longer used. It is derived from the
  configuration now instead of written down beside it.

- **Seven documentation pages said something the source contradicts.** Two code examples could
  not run as written — the `sync`-mode example was missing the master switch and the
  connection, and the reporting example called a function that does not exist anywhere. The
  database reference claimed neither table is touched outside `batch` mode, when the
  dead-letter table is written from `queue` mode too; it also said "two migrations" (there are
  four) and called the buffer "exactly-once" where the rest of the documentation correctly
  promises at-least-once. Troubleshooting listed host and site id as the most common cause of
  "no data" without mentioning the master switch. The site-search middleware records any
  response below 400, not only a 2xx.

- **The consent seam's own comment overstated it.** `tracking.gate` is consulted **last** and
  can only refuse: returning `true` does not force tracking past a bot check, an opt-out cookie
  or Do-Not-Track. The shipped config said it "wins" and could "force it", which is the kind of
  promise a consumer builds a consent layer on.


- The Octane guide now says what "shared" does not cover. Its line is drawn at request
  state, which is the right line for the property it is about — one request seeing
  another's data — and the connection details hold none of it. They are, however, read
  once and shared from then on, so an application that varies this package's configuration
  per request or per queued job keeps whatever a long-lived worker resolved first. That is
  the ordinary multi-tenant shape, where each tenant tracks into its own site.

  The new section also names the workaround that does not work, because it is the first one
  a reader reaches for: forgetting the connection instance leaves the payload builder, the
  sender and the gate holding the object that was current when they were built.

## [0.23.0] - 2026-08-23

### Added

- **Two more AI crawlers are recognized: `Meta-ExternalTest` and `Reflectionbot`.** Refreshed from
  the upstream catalog by the weekly sync, bringing the list to 164 tokens. Nothing else changed —
  the rest of that diff is the generator reflowing five entries per line.

  Tokens are matched case-insensitively as SUBSTRINGS, so a careless entry would classify real
  visitors as bots and silently drop their page views. Both were checked against ordinary Chrome,
  Safari, Firefox, curl, Postman, Googlebot, bingbot and `facebookexternalhit` user agents before
  merging: no false positives, and no duplicate token in the list.

### Changed

- **`ReportClient` now declares the 22 convenience helpers it always answered to.** The facade
  advertised all 27 methods and pointed at a contract carrying 5, so the documented way to reach
  the read side — inject `ReportClient` — could not call `visitsSummary()` under static analysis
  while the README advertised it. It ran fine; the promise simply was not written down anywhere a
  type checker could read. Same API, two different answers depending on how you reached it.

  **Implementing the contract is still a five-method job.** Every helper is one call to `get()`
  with a fixed Matomo method name, and `ResolvesCommonReports` — which declares `get()` abstract
  for exactly this purpose — supplies all 22 from it. An existing implementation adds one `use`
  statement; both shipped implementations needed no change at all, which is the measurement
  behind calling this cheap rather than the assumption.

  Classified as a **minor** rather than a patch: it widens a published interface, so a
  third-party implementation that did not use the trait must add it. None is known, and on 0.x a
  minor is the right vehicle for that.

  A second interface was considered and rejected. It would leave anyone who injects
  `ReportClient` — the bound name, the documented one — looking at five methods while the facade
  advertises 27 — the reported defect left in place under a new name. A discoverability
  problem is not fixed by adding something else to discover.

  The facade's advertised surface and the contract are now held against each other in both
  directions, so the two can no longer drift apart the way they had.

## [0.22.0] - 2026-08-21

### Added

- **`MATOMO_JS_ENABLED` turns the client-side tracker off from the environment.** `js.enabled`
  was the one switch in that block with no env seam, and the default is unchanged — but a
  deployment whose `config/matomo-analytics.php` is template-managed had no supported way to say
  no. Editing the literal there is reverted by the next sync, silently: the switch reads "off"
  until one day it does not, and nothing announces it.

  It governs **both** doors that put tracking into a page — `@matomoScript` and the `<noscript>`
  pixel behind `@matomoNoscript`. `@matomoWebVitals` has its own switch and ships off;
  `@matomoOptOut` is not tracking and keeps working. That table is now in the client-side guide,
  because the list is easy to get wrong from memory: a consuming project's audit named two of the
  four directives, and the two it missed were the pixel and the Web Vitals reporter.

- **`matomo:test` now names settings the running application cannot see.** A configuration cache
  built before a package update, and never rebuilt, is the whole truth at runtime — the package's
  recursive config merge does not run at all when the config is cached — so a setting added since
  is simply absent. Nothing throws; the code fallback answers instead, which is usually even
  correct. Usually is not always, and the exceptions are the settings you set on purpose.

  Advisory, never fatal: the command is asked when something is already wrong, and turning a
  diagnostic into a failure removes the diagnosis.

### Fixed

- **`MatomoFake::assertTracked()` now says which type its callback receives.** Asking by an inner
  hit type matches a hit decorated with `CustomParameters`, and the callback then gets the
  DECORATOR rather than the inner hit — so the natural-looking
  `fn (SiteSearch $s) => …` raises a TypeError as soon as anybody wraps that hit. The behavior is
  unchanged and was already correct; what was missing is the sentence that stops you writing the
  narrow form. Narrow inside the body instead: `fn (Hit $hit) => $hit instanceof SiteSearch && …`.

- **`illuminate/translation` is now declared, and on a lean install the shipped privacy-policy
  partial no longer fatals.** The package ships translations for seven locales, registers them
  from its service provider and renders them from a Blade partial — but never named the
  component that provides the `translator` binding, and no declared component pulls it in. On a
  full `laravel/framework` install nothing showed; on the slim install this package's separate
  `illuminate/*` requirements exist for, rendering the partial threw.

- **The daily dead-letter prune no longer fails when the table does not exist.** 0.21.0
  introduced that prune and ran it unconditionally, so an installation that suppresses the
  package migrations — or switches the dead-letter store off — got an "Undefined table" error
  every night. That is the opposite of what 0.21.0 set out to do. A cleanup task has nothing to
  report when the thing it cleans up is absent.

- **`batch.dead_letter.enabled = false` now takes effect in `queue` mode, not only in `batch`
  mode.** The migration has always documented the opt-out and the batch flusher has always
  honored it, but the queued delivery job wrote to the store regardless — so switching it off
  left rows being written to a store you had switched off.

  What the opt-out means depends on the mode, and the two differ: in `batch` mode the failed
  batch stays in the buffer; in `queue` mode there is no buffer to stay in, so the job fails the
  ordinary way and the batch lands in `failed_jobs`. Both are visible and neither loses hits.
  The migration comment now says both, because its single clause was true for batch users and
  misleading for queue users.


## [0.21.0] - 2026-08-19

**This release starts deleting something, and that is the one thing to decide before you
upgrade.** Batches that gave up on delivery are parked in `matomo_dead_letters`, and until now
nothing ever removed them — the two commands that can are both things a person has to run, so
the installation where nothing goes wrong is exactly the one whose table only grew. A daily
prune now clears entries older than 30 days. If you have been treating that table as a
permanent archive, set `batch.dead_letter.retention_days` to `0` first.

Everything else is the package keeping promises it had already made: its dependency list says
component-only, and shipped code no longer contradicts that; its bot list says it tracks the
AI-crawler landscape, and it is current again.

### Added

- **Dead letters are now deleted after 30 days**, by a daily scheduled prune. Until now
  nothing ever removed them: `matomo:replay` deletes an entry when it re-queues it and
  `--prune` empties the queue on demand, but both need someone to run them — so an
  installation where nothing goes wrong accumulated the table forever, one full batch of hits
  per row. Set `batch.dead_letter.retention_days` to `0` to keep the old behavior, or lower it if
  your queue is large. `matomo:replay --prune-older-than=N` does it by hand.

  There is a second reason beyond disk. A hit's timestamp is stamped when the payload is
  built, so replaying a month-old dead letter sends a month-old timestamp — and Matomo refuses
  a backdated hit older than about a day unless the request carries `token_auth`. Without one
  it records the hit at today's date instead, moving old visits into the current report. An
  entry nobody has looked at in a month is past the point where replaying it helps.

- Two AI crawler tokens in the shipped bot list, `ExaSearchBot` and `Lightpanda`, taking it
  from 160 to 162. The weekly sync had been generating these updates since 2026-06-29 and
  pushing them to a branch, but the step that opens the pull request was failing silently, so
  none of them reached a release. If you rely on `bots.*` to keep crawler traffic out of your
  analytics, those two were being counted as visitors.

### Fixed

- **The package no longer calls any Laravel Foundation global helper in shipped code**, which
  is what its component-only dependency list has always promised. Four `config()` calls in the
  migrations and one translation helper in the publishable privacy-policy view would have
  fataled on an install that has the `illuminate/*` components but not `laravel/framework` —
  the migrations at the worst possible moment, since they run when the package is first
  installed. They now go through `Illuminate\Support\Facades`, which the package does depend on.

  The guard that was supposed to catch this searched only `src/` and `config/`. It searches the
  whole shipped tree now.

- The `failed_at` index on the dead-letter table is back. It shipped originally, was removed
  in 0.19.0 because no query read it, and is now the column the retention prune filters on.

- The AI-crawler sync now fails when it cannot open its pull request, instead of reporting
  success. Seven weekly runs in a row were green while delivering nothing, and a green check
  on a sync job reads as "the list is current". It also reuses one branch rather than opening
  a new one per run.

## [0.20.0] - 2026-08-19

**This release is about what your error dashboard shows you.** A Matomo endpoint that
times out is a normal event on a public network, not an incident — the hits are
fire-and-forget telemetry and no visitor ever waits on one. Until now a timeout
nevertheless filed an error in your application, on every retry, and nothing in this
package's configuration could stop it.

**Two behavior changes come with the fix, and they are why this is a minor rather than a
patch.** A queued batch is now given up on after `queue.tries` attempts — around twenty
minutes on the defaults — instead of after a day; and a batch that spends its budget goes
to the dead-letter table rather than into `failed_jobs`, which is where `batch` mode
already put one. If you watch `failed_jobs` for undelivered hits, watch
`matomo:replay --list` instead.

### Fixed

- **A Matomo that times out no longer files an error in your application.** In `queue`
  mode the delivery job rethrew whatever the transport raised. Laravel's queue worker
  catches what a job throws and hands it to your application's own exception handler, so
  a routine network timeout became an entry in your error tracker — past this package's
  `report_after_attempts` threshold, past its per-signature throttle, and past
  `resilience.reporting.channel = 'silent'`. None of those three settings could reach it.

  It also meant `resilience.never_throw` was not true for queued delivery, while it held
  for the synchronous path. The configuration says "a tracking error never surfaces in
  your application"; for the mode most installations run, it did.

  Two production applications were affected, with 51 and 14 recorded occurrences of the
  same five-second timeout. Nobody was affected by the timeouts themselves — the hits are
  fire-and-forget telemetry and no visitor waits on them — which is the point: the only
  thing they produced was noise in the place you look when something is genuinely wrong.

  The job now absorbs a delivery failure and releases itself for retry with the same
  backoff the worker would have applied, so pacing and escalation are unchanged. Alerting
  stays where it was designed to live, in the reporter, where the three settings apply.
  Set `resilience.never_throw` to `false` to keep the old behavior.

- **A list setting whose key is missing now answers with the values the package ships, not with
  an empty list.** This matters in one situation, and it is a bad one to be in silently: an
  application whose configuration cache was built before this package merged its configuration
  recursively, and never rebuilt since. A fresh cache carries the shipped values; a stale one
  does not, and a missing key then read as "the list is empty".

  Empty is the wrong answer for five of the thirteen list settings, and two of them are privacy
  settings. `privacy.redact.query_params` ships eighteen entries — and "redaction is running"
  and "redaction does nothing" look exactly alike from the outside. On an affected installation
  the redaction lists were inert.

  A list you emptied **on purpose** stays empty. Clearing a list is a decision, and a fallback
  that overrode it would quietly switch rules back on that you turned off; only an absent key
  falls back.

  Nothing to do on your side, and no configuration change is needed. If you were affected, the
  narrower fix remains worth doing anyway: rebuild the configuration cache.

### Changed

- **A batch that exhausts its delivery attempts is dead-lettered instead of landing in
  `failed_jobs`.** `queue` mode now ends a failed batch exactly the way `batch` mode
  already did: the payloads go to the dead-letter table with their attempt count and last
  error, a `TrackingFailed` event fires, and `matomo:replay` puts them back. Nothing is
  lost, and the recovery path is the one the documentation already described.

- **`queue.tries` now bounds the retry loop, which it previously did not.** Laravel skips
  its own max-attempts check whenever a job defines `retryUntil()`, and this job always
  does — so the documented, configurable, defaulted attempt budget governed nothing, and a
  batch retried against an unreachable Matomo until the 24-hour deadline instead of five
  times. With the backoff settling at 15 minutes and the report throttle set to the same
  15 minutes, that also meant the throttle stopped almost nothing.

  A batch aimed at an unreachable Matomo is now given up on after `queue.tries` attempts
  — roughly twenty minutes on the defaults — rather than after a day. If you were relying
  on the day-long window, raise `queue.tries`; `queue.retry_until_minutes` still bounds
  the outside.

- **The dead-letter table no longer carries an index on `failed_at`.** No query used it — the
  recent list orders by `id`, the replay walks by `id`, the cleanup deletes by `id` — so every
  insert paid for an index nothing read. A migration removes it from existing installations and
  restores it on rollback, both conditionally, so it runs cleanly from zero as well as on the
  old shape.

  The drop matches the index by COLUMN rather than by a name built by hand. Laravel prefixes
  index names when `prefix_indexes` is set, which is the shipped default for MySQL and
  PostgreSQL; an installation with a table prefix would otherwise have kept the index while the
  migration recorded itself as run, and a rollback would have added a second one beside it.

- **Development-only: this package's own `composer` scripts changed.** `test:coverage` now
  invokes the test runner with the coverage driver pointed at the project directory, the two
  mutation-testing scripts name their target directory explicitly instead of inheriting it, and
  `test:database` covers the Redis suite alongside the PostgreSQL and MySQL ones.

  Nothing an application installs behaves differently: no class, configuration key, route, view
  or translation moved, and the package's requirements are unchanged. The scripts are noted here
  only because the manifest that carries them is part of the published package — running them is
  something this repository does to itself.

## [0.19.2] - 2026-08-05

**A metadata release: the published package is byte-identical to 0.19.1 apart from this
entry.** Nothing this package ships changed — not the source, not the configuration, not the
routes, views or translations, and not the manifest. If you are on 0.19.1 there is nothing to
install and nothing further to read; this entry exists so that a version with no visible diff
does not send anyone through the tag looking for what they missed.

What moved is development infrastructure that never leaves the private repository: the CI
lanes now route each job to its own agent pool. It is recorded here rather than nowhere,
because a released version with a silent changelog is worse than an honest one.

## [0.19.1] - 2026-08-04

**Housekeeping. Nothing you install changes.** The package's own requirements, its shipped
code, its configuration and its documented behavior are all identical to 0.19.0.

### Changed

- **The published manifest no longer lists `cweagans/composer-patches` as a development
  dependency.** Nothing you install changes — a library's `require-dev` is not installed by
  the applications that require it, and the package's own `require` block is untouched. The
  entry is gone because the thing it existed for is gone: this package carried one composer
  patch, teaching the mutation runner to read the coverage report format that
  `phpunit/php-code-coverage` 14 writes, and that fix has been released upstream in
  `pest-plugin-mutate` 5.0.1. A patch manager with no patches left is a dependency that does
  nothing, and a patch nobody removes is a fork nobody admits to.

## [0.19.0] - 2026-08-03

**Upgrading from 0.17.0 or earlier? Read the 0.18.0 entry below as well.** That version was
prepared but never published as its own release, so its changes ship here — including the ones
that move reported figures in three directions. 0.18.0 was never installable and never will be;
this release is the first one that carries it.

**If you have a published `config/matomo-analytics.php` *and* you run `config:cache`, read
this before upgrading — there is a case where tracking stops.** Everything below is a fix,
and every one of them is about the same thing: what this package does when a config key is
*missing* from your file. Two answers were the opposite of the documented default, and
nested keys had no answer at all.

- **Nested defaults now reach you.** A section you published froze at that day's shape, so a
  key added later was absent rather than defaulted — `privacy.redact` meant redaction doing
  nothing, `spa.adapters` meant no adapter at all. This is the change that reaches the most
  installations, and it needs nothing from you.
- **Tracking stops** if your published file is missing `enabled` **and** your config is
  cached. The shipped file has said `false` since 0.16.0 — "installing this package must
  never start tracking anyone" — while three of the four places that read it fell back to
  `true`. Put the shipped line back — `'enabled' => env('MATOMO_ENABLED', false)` — and set
  `MATOMO_ENABLED=true` in the environments you want tracked. The `false` is deliberate:
  writing `true` into the file makes the *file* enable tracking, so every environment that
  inherits it starts tracking without anyone deciding to.
- **IP anonymization switches on** if your file is missing `anonymize_ip` and your config is
  cached. It has shipped as `true` since 0.16.0 and the code fell back to `false`, so hits
  carried full addresses. Reported visitor counts may shift slightly, because a truncated
  address is a different input to visitor identification.
- **The heartbeat timer switches on** under the same conditions. `js.heartbeat` ships as
  `15` and fell back to `0`, which means off, so a published `js` section without the key
  had no heartbeat at all. Time-on-page figures rise for those installations — the earlier
  numbers were the ones that were wrong.

**Why both keys need the cache to bite:** `enabled` and `anonymize_ip` are top-level and
have shipped in every published version, so a file you published simply *has* them.
Laravel's merge also fills a missing top-level key back in from the shipped file. It takes
a file you trimmed by hand *and* a `config:cache` that froze it that way for the fallback
to be reached at all. If either is untrue for you, nothing changes.

**If you cache your config, rebuild the cache after upgrading** — `php artisan config:cache`.
That one command re-runs the merge and picks up everything added since. Re-publishing the
file does nothing on its own while a stale cache is in place.

It is a minor and not a patch for the reason 0.17.0 and 0.18.0 give: every item is a fix, and
a patch tells every auto-merge policy there is that nothing needs reading. This one has a
sentence someone should read first. The full walkthrough, including what to check if
redaction or SPA tracking looks inert afterwards, is in
<https://docs.pushery.com/matomo-analytics-for-laravel/guides/upgrading>.

### Added

- **`@matomoNoscript` / `Snippet::noscript()`** — the no-JavaScript tracking pixel on its
  own, for placement inside `<body>`. Inside `<head>`, where `@matomoScript` is documented
  to go, the HTML spec allows a `<noscript>` to contain only `<link>`, `<style>` and
  `<meta>`, so the pixel's `<img>` is a parse error there. Browsers recover from it, so
  this is validator noise rather than breakage — which is exactly why **nothing changes by
  default**: dropping the pixel from `@matomoScript` would cost every consumer their no-JS
  tracking to quiet a validator, and most would never read this note. For a validator-clean
  page, set `js.noscript` to `false` and place `@matomoNoscript` in the body instead.

### Changed

- **Seven more `illuminate/*` components are now required** — `auth`, `cache`, `config`,
  `cookie`, `events`, `log` and `view`. They were always used; the manifest simply did not
  say so, and inside a normal Laravel application they are present anyway. Nothing changes
  for such an application. See the lean-install entry below for what this fixes.
- **The bundled AI-crawler list is refreshed**, 150 tokens to 160. New entries include
  `Cursor`, `Retool`, `TongyiBot`, `YiyanBot`, `HarkBot`, `HIFIBot`, `AIWebIndex`,
  `amazon-QBusiness`, `Mozilla-Tabstack` and `Instapaper`; nothing was dropped. The shipped
  default `bots.track => false` means these are now *excluded* from your reports rather than
  counted as visits, so a site with meaningful AI-crawler traffic will see a small drop in
  visits — the lower number is the correct one.

### Fixed

- **The consecutive-failure counter no longer loses increments under two drainers.** It
  read the value and wrote it back, so a scheduled `matomo:flush` failing at the same
  moment as a `matomo:work` daemon counted one failure instead of two. Nothing was ever
  lost from the buffer — but a stuck batch reached `batch.max_attempts` later than
  configured, which is the one thing that counter exists to time. It now uses the cache
  store's own atomic increment, and initializes the key with an expiry — without one
  Laravel's `add()` does not reach the store's atomic path, which would have left the same
  race open for the first failure after every reset.
- **`matomo:work` shuts down between runs instead of wherever the signal lands.** The
  daemon now traps `SIGTERM`/`SIGINT` and finishes the current flush before exiting, the
  same way `queue:work` does. Signal handling is skipped entirely on a host without
  `ext-pcntl`, which this package does not require.
- **The Blade directives emit fully-qualified class names.** Their output is compiled into
  your application's view cache, a plain PHP file in the global namespace, where a bare
  `App::make(...)` resolves only through the `App` class alias. An application that does not
  register aliases got a fatal error on a rendered page.
- **`js.heartbeat` falls back to the 15 seconds the shipped config declares**, not to `0`.
  Zero means "no heartbeat timer", so a published `js` section that predated the key
  measured no time on page at all while the file it came from said 15. An explicit `0`
  still switches the timer off — that is the documented way to disable it.

- **The lean, component-only install this package advertises now actually works.**
  `composer.json` requires individual `illuminate/*` components rather than
  `laravel/framework`, but the shipped code reached for 29 global helpers — `config()`,
  `app()`, `event()`, `now()`, `request()`, `url()`, `report()`, `abort()`, `response()`
  — that ship *only* with the framework's Foundation. In a normal Laravel application
  they are always there, which is exactly why this stayed invisible; on the slim install
  the manifest describes, they are a fatal.

  Every one is now the equivalent from `Illuminate\Support\Facades` (or, for the two with
  no facade, the thing the helper does internally). Behavior is unchanged — a facade is
  a static proxy over the same container binding the helper resolves.

  **And the binding has to be there.** A facade is a proxy: `Cache::get()` asks the
  container for `cache`, registered by `illuminate/cache`. Seven such components were
  reached at runtime and named in no `require`, so the swap moved the fatal rather than
  removing it — from a missing function to a missing binding, in the same place, on the
  same install. They are declared now (see *Changed*), and the guard resolves each facade
  to the component that really provides it instead of to the namespace its class sits in.

- **A published `config/matomo-analytics.php` is no longer a ceiling.** Laravel's
  `mergeConfigFrom` is a flat `array_merge`, so a top-level key present in your published
  file replaced the shipped one *whole*. With a config nested three levels deep, that meant
  every section you published froze at the shape it had on publication day: a subkey added
  by a later release did not fall back to its default, it was simply absent — and the
  readers that hurt most take no default at all. `privacy.redact.query_params` answering
  `[]` is URL redaction silently doing nothing; `spa.adapters` answering `[]` is no adapter
  at all.

  The merge now recurses into nested sections, so a key you never mentioned arrives with
  its shipped default while everything you *did* set still wins.

  **Lists are deliberately NOT merged.** If your file sets `bots.deny` or
  `privacy.redact.query_params`, that list is taken exactly as written — including when you
  emptied it. The obvious alternative (`array_replace_recursive`) merges lists by index and
  would hand entries back that you deleted on purpose, which for a privacy list means
  turning a setting back on behind your back. The rule is: **a map is a namespace and gets
  merged, a list is a value and is taken whole.**

  **This does not reach a cached config.** `config:cache` freezes the resolved config and
  the framework skips merging entirely, by design. If you cache your config, re-publish it
  (`--tag=matomo-analytics-config`) to pick up keys added since you last did.

- **A published config that predates a key no longer flips that key's meaning.** Every
  boolean this package reads carries a code-level fallback for the case where the key is
  absent from your config — and two of those fallbacks contradicted the value the shipped
  config file declares. They now agree, in the only direction that is safe:
  - **IP anonymization defaults to ON.** `anonymize_ip` has shipped as `true` since
    0.16.0, but the code fell back to `false`. If your published config still lacks the
    key, hits carried full IP addresses despite the shipped default and despite what your
    privacy policy most likely says.
  - **The master switch defaults to OFF.** `enabled` has shipped as `false` since 0.16.0
    — installing this package must never start tracking anyone — but three of the four
    places that read it fell back to `true`. A published config without the key therefore
    tracked, which is the exact opposite of the guarantee 0.16.0 introduced.

  **Who this reaches, and what changes for them.** Only installations whose *published*
  `config/matomo-analytics.php` is missing one of these keys — which means you trimmed the
  file to the settings you tune, since both have shipped at the top level of every published
  version. `mergeConfigFrom` fills a missing top-level key back in, so this only bites once
  `config:cache` freezes the file as it stands. If your config carries both keys (the shipped
  file does), or you do not cache, nothing changes.

  If you are in that group and were tracking, **tracking now stops until the key is back**.
  The package cannot tell "the key is missing" from "the operator wants it off", and for a
  dormancy guarantee those have to resolve the same way. Put the shipped line back —
  `'enabled' => env('MATOMO_ENABLED', false)` — and set `MATOMO_ENABLED=true` in the
  environments you want tracked, then rebuild the config cache. Re-publishing the whole file
  gives you the same line plus everything else added since, but it changes nothing at all
  until `php artisan config:cache` runs again.

## [0.18.0] - 2026-07-31

> **Never published.** This version was prepared on the date above and the release was
> deliberately not performed; no `v0.18.0` tag exists and it was never on Packagist. Everything
> below ships in **0.19.0** instead. The section is kept because the changes are real and anyone
> upgrading from 0.17.0 needs to read it — but do not go looking for the version itself.

**This release changes the numbers a tracked site reports — in three directions, and the new
ones are the correct ones.** Read this before upgrading if you run batch mode or sit behind a
reverse proxy.

- **Visitors go UP behind a proxy with `ip_header` set.** The cookieless visitor id now derives
  from the same client IP the hit reports, so visitors that all collapsed into one because they
  shared the edge IP are counted distinctly. If you do not set `ip_header`, nothing changes.
- **Actions and visits go DOWN if you run two drainers.** The database buffer's claim is now
  race-safe, so a scheduled `matomo:flush` running alongside a `matomo:work` daemon can no
  longer both claim and send the same hits. Those were double counted; they are not any more.
- **Hits that used to disappear now arrive.** A Redis-buffer flush that died after claiming but
  before acknowledging stranded its hits forever; they are now reclaimed and sent. Expect a
  one-off catch-up on the first flush after upgrading if that has ever happened to you.

It is a minor rather than a patch for exactly that reason: every item above is a fix, and a
patch signals "safe, nothing to think about" to every auto-merge policy there is. These move
reported figures, so someone should read a sentence first.

Two security hardenings ship with it, and one config key is gone. The package also now declares
four `illuminate/*` components it has always imported — invisible in a full Laravel application,
and the reason the package could not actually run on the lean, component-only installation it
advertises.

### Security

- The client-side snippet now escapes `<`, `>`, `&` and quotes in every embedded
  value (matching Laravel's `Js::from()` encoding). A tracked value containing
  `</script>` — for example a client-side custom-dimension sourced from a user
  attribute — can no longer close the tracker's `<script>` block and inject markup.
- The Web Vitals ingest endpoint now accepts only the three standard ratings
  (`good`, `needs-improvement`, `poor`); any other client-supplied `rating` is
  dropped instead of being recorded verbatim as the Matomo event name.

### Fixed

- URL redaction now also covers array-style query parameters (`token[]=`, `token[0]=`),
  which previously slipped through unredacted because of the bracket between the key and
  the `=`.
- `matomo:replay` now removes each dead-letter entry as soon as its hits are buffered,
  rather than deleting them all at the end. An interruption mid-replay can no longer
  re-push already-replayed entries on the next run.
- The file buffer stamps a freshly claimed spool file with the claim time (rather than
  inheriting the queue's older modification time), so a concurrent stale-reclaim cannot
  treat an in-flight claim on an idle spool as abandoned and re-queue it.
- The Redis buffer now reclaims in-flight hits from a crashed flush. Each claim's
  processing list is registered in a sorted set keyed by claim time; a later claim
  moves any list older than `stale_after_minutes` back onto the queue. Previously a
  flush that died after claiming but before ack/release left those hits stranded in
  the processing list forever — they are now recovered, matching the database and
  file drivers' "a crashed flush loses nothing" guarantee.
- The database buffer's claim is now race-safe against concurrent drainers. The claim
  UPDATE re-asserts the unclaimed/stale predicate (not only the preceding SELECT), so
  two flushers running at once — for example the scheduled `matomo:flush` alongside a
  `matomo:work` daemon — can no longer both claim and send the same hits, which had
  double-counted visits and actions. Portable across SQLite, PostgreSQL and MySQL.
- Scheduled-flush observability hardening (batch mode):
  - The `matomo:flush` schedule now bounds its overlap lock to 10 minutes instead of
    the framework's 24-hour default, so a hard-killed (SIGKILL/OOM) flush can no longer
    stall the every-minute drain for a full day.
  - `matomo:flush` now exits non-zero once the drain has failed `report_after_attempts`
    times in a row, so the scheduler's failure hooks and exit-code monitors detect a
    stuck drain instead of seeing a green run.
  - The batch flusher now honors `report_after_attempts` before alerting (matching the
    queue path), so a single transient blip no longer pages monitoring on the first
    failed flush.
- The cookieless visitor id now derives from the same client IP as the tracked
  `cip` (honoring the configured `ip_header`). Behind a reverse proxy with
  `ip_header` set, every cookieless visitor previously hashed from the shared edge
  IP and collapsed into a single visitor; they are now counted distinctly. The
  default (no `ip_header`) is unchanged.
- The batch flusher no longer dead-letters a batch on a back-pressure or timeout
  status (`408`, `423`, `425`, `429`). These are transient and are now retried with
  back-off like a `5xx`, so a rate-limited Matomo instance can no longer drain a whole
  backlog into the dead-letter queue in a single flush. A genuine `4xx` poison (e.g.
  `400`) is still dead-lettered immediately.
- Documentation: the `batch` dispatch-mode config comment now describes the real
  behavior (a cross-request buffer drained by `matomo:flush`/`matomo:work`) instead
  of claiming it "behaves as queue".
- **The package now requires the four `illuminate/*` components it has always imported.**
  `illuminate/console`, `illuminate/database`, `illuminate/redis` and `illuminate/routing`
  were used by shipped code — every Artisan command extends `Illuminate\Console\Command`,
  the database and Redis buffers type-hint their connections, and the tracking middleware
  type-hints `Illuminate\Routing\Route` — while `composer.json` required none of them.
  In a full Laravel application this was invisible, because `laravel/framework` provides
  every one. On the lean, component-only installation this package advertises by requiring
  components rather than the framework, `illuminate/redis` and `illuminate/routing` were
  not installed at all, so the buffer, the middleware and the package's own routes could
  not load. If you install into a full Laravel application, nothing changes for you.

### Changed

- `CONTRIBUTING.md` now states the local PHP requirement. The package installs on 8.4.0,
  but working **on** it needs 8.4.1 or newer, because the test toolchain pulls
  `symfony/process`. On exactly 8.4.0 `composer install` fails with a message naming
  `symfony/process`, which points away from the cause.

### Removed

- The unused `resilience.durability` config key. It was never read by any code path,
  so setting `MATOMO_DURABILITY` had no effect.


## [0.17.0] - 2026-07-27

**This release changes the page-view numbers a tracked site reports — they go down.** It adds
no feature and removes no option, and it reaches you only if you set `spa.enabled` to `true`
(off by default). What it corrects is a miscount: soft-navigation tracking recorded a second
page view on every *full* page load of a Livewire application. The counts you were seeing were
inflated; the lower ones after this upgrade are the correct ones. Visits, visitors and every
unique-based metric are unaffected. It is a minor rather than a patch for exactly that reason —
the numbers move, so someone should read this before it lands. Details, including the one case
that genuinely loses a page view, are in
<https://docs.pushery.com/matomo-analytics-for-laravel/guides/upgrading>.

### Fixed

- **SPA tracking counted every full page load twice in a Livewire application.** Livewire's
  navigation plugin ends its start-up by firing its `navigated` event once unconditionally,
  and that reaches the page as `livewire:navigated` — on every **hard** load, at the URL the
  snippet has just recorded a page view for, and whether or not the application uses
  `wire:navigate` anywhere. The `livewire` adapter listened for that event and recorded a
  second, virtual page view for it. Adapters now record only when the URL actually changes.
  **Expect the page-view count of an affected site to fall**: the numbers it was reporting
  were inflated, and this is the correction, not a loss of data. `window.matomoTrackPageView()`
  is deliberately unaffected and still records at an unchanged URL, which is what its
  documented uses (a tab switch, a modal route) need. The same rule makes `livewire` and
  `generic` safe to enable together — one navigation is no longer recorded by both.
- **The first virtual page view of a visit reported no referrer.** It was sent empty, so
  Matomo read the first soft navigation as a direct entry and every flow report started one
  step late. It now carries the URL the browser loaded normally.

### Changed

- The shipped configuration no longer describes `wire:navigate` as WireKit's directive. It is
  Livewire's; WireKit is a component library that requires Livewire, which is the actual —
  and checkable — reason the `livewire` adapter covers a WireKit application.

## [0.16.0] - 2026-07-26

**This release changes three shipped defaults.** Each change makes the quiet option the safe
one, and each can break an existing setup in exactly one direction: tracking stops. Nothing
starts collecting more than it did before. If you published `config/matomo-analytics.php`,
your file's values win and none of it reaches you — see
<https://docs.pushery.com/matomo-analytics-for-laravel/guides/upgrading>.

### Changed

- **BREAKING — the package now ships dormant.** `enabled` defaults to `false`, so installing
  it tracks nobody until you set `MATOMO_ENABLED=true`. Previously the default was `true` and
  dormancy depended on `host`/`site_id` happening to be unset, which is not the same promise:
  it made tracking start as a side effect of configuration rather than as a decision. If you
  are upgrading and want tracking to continue, set `MATOMO_ENABLED=true`.
- **BREAKING — `MATOMO_URL` is no longer read.** `host` was `env('MATOMO_HOST', env('MATOMO_URL'))`,
  falling back to a key this package does not own. An application that already had its own
  Matomo integration reading `MATOMO_URL` was therefore activating this package by configuring
  that one — and, together with the default above, could start tracking every visitor
  (including those who had refused consent in the application's own gate) after a routine
  `.env` edit. Only `MATOMO_HOST` is read now. Reported from a real consumer integration.
- **BREAKING — safer privacy defaults.** `anonymize_ip` now defaults to `true`, and
  `visitor.user_id` to `null` instead of `'auth'`. Both are still fully configurable; what
  changed is which one you have to ask for. Attaching an authenticated user id to every hit,
  and storing full IP addresses, are deliberate choices rather than starting points.
- `matomo:install` now names `MATOMO_ENABLED` first — following its old hint configured a
  package that then stayed silent, with nothing explaining why. `matomo:test` warns when
  tracking is disabled and probes the connection anyway, since knowing the credentials work is
  exactly what you want before flipping the switch.

### Added

- **The privacy-policy partial is translated into all seven shipped locales** (de, en, es, fr,
  it, nl, pt). It was hardcoded English, so a non-English site published an English privacy
  paragraph onto a public page — the one piece of user-facing prose this package renders. The
  text now lives in `lang/<locale>/messages.php` and is published with the
  `matomo-analytics-lang` tag; `$heading` still overrides just the heading.

### Notes

- The privacy-policy partial asserts that no consent banner is required. That holds for the
  SHIPPED configuration — cookieless, anonymized IPs, no user id, nothing shared with third
  parties. Once you publish the lang files the text is yours: if you relax one of those
  settings, change the sentence that depends on it.
- **If your application has its own consent layer, wire it into `tracking.gate`** rather than
  gating this package from outside. It takes an invokable class-string or a closure
  `fn(Request, $hit): ?bool`, is consulted before every hit, and overrides the built-in rules.
  It is not new — it is now documented as the seam it is rather than as one setting among
  several.

## [0.15.0] - 2026-07-26

### Added

- **An umbrella publish tag.** `php artisan vendor:publish --tag=matomo-analytics` now
  publishes every resource group at once — config, migrations, views and translations —
  instead of requiring four separate invocations. Each group keeps its specific tag
  (`matomo-analytics-config`, `-migrations`, `-views`, `-lang`), so nothing that worked
  before changes.
- **A bundled Laravel Boost skill** at `resources/boost/skills/matomo-analytics-for-laravel/SKILL.md`.
  Boost surfaces it inside applications that install this package, so an assistant
  working in a consuming app gets the package's real integration guidance — the facade
  entry points, the transmission modes, the testing fakes and the anti-patterns —
  instead of inferring an API.
- Translations are prepared for seven locales (de, en, es, fr, it, nl, pt). The files
  are in place and published under the `matomo-analytics-lang` tag; the package's one
  translatable string (the privacy-policy partial) is not extracted yet.
- A Laravel-versions badge in the README, derived from the package's own constraint
  rather than hand-maintained.
- The release now generates a lean-dist public `.gitattributes` that `export-ignore`s
  the repo meta (`art/`, the CHANGELOG, CONTRIBUTING, and `.github`). The installed
  Composer package is therefore byte-for-byte as lean as before while those files
  remain visible in the public repository. Previously this package generated no public
  `.gitattributes`, so the dist carried them.

### Removed

- The `bots.record_ai_dimension` configuration key. It was published in the config file
  but never read anywhere in the package, so setting it had no effect. Removing it changes
  no behavior; if your published `config/matomo-analytics.php` still carries the line, it
  can be deleted.

### Changed

- **Published migrations now sort after your own.** They are published through
  `publishesMigrations()`, which rewrites the bundled `0001_01_01_00000N` ordering
  prefix to the publish date. Previously the bundled prefix was copied verbatim, so a
  published migration sorted before every migration the application already had. If
  you published the migrations before, the existing files are untouched — this affects
  only migrations published from now on.
- The configuration file no longer describes `batch` mode as unfinished. It said the
  mode "arrives in a later release; currently behaves as 'queue'", which stopped being
  true when the cross-request buffer shipped: `batch` has working `database`, `redis`,
  `file` and `array` drivers, flushed by `matomo:flush` / `matomo:work`.
- `CONTRIBUTING.md` no longer tells readers of the published package to run gate
  commands whose inputs are not part of it.
- **Documentation moved to <https://docs.pushery.com/matomo-analytics-for-laravel/>.**
  The README carried the entire manual; it is now a showcase that links to the
  documentation site, where the same material is structured as a browsable,
  searchable set of pages — installation, configuration, tracking, delivery, privacy
  and gating, reporting, guides and a full reference for every config key, command,
  event, contract and database table. Nothing was dropped: the pages cover more than
  the README did, including several settings and extension points it never mentioned.

## [0.14.3] - 2026-07-07

### Fixed

- `SendHitsJob::retryUntil()` no longer fatals host applications that run
  `Date::use(CarbonImmutable::class)`: the return type is now
  `DateTimeInterface` (the queue contract's shape) instead of the concrete
  `Illuminate\Support\Carbon`, which rejected the `CarbonImmutable` that
  `now()` returns there and failed every dispatched hit with a `TypeError`.

## [0.14.2] - 2026-07-05

### Changed

- The database batch buffer is now verified against real MySQL 8.4 and PostgreSQL — the engines
  Laravel Cloud runs — in addition to SQLite, so buffered hit delivery is proven on the database
  you deploy to.

## [0.14.1] - 2026-07-04

### Documentation

- Clarified the release-annotation docs: `config('app.version')` is not defined by stock
  Laravel, so `matomo:annotate --release` needs an explicit `--app-version` (or an app
  version key) to include the version in the marker.

## [0.14.0] - 2026-07-03

### Added

- **Load simulator.** `matomo:load-sim` fires N synthetic hits through the real build → buffer →
  flush pipeline and reports enqueue/flush throughput, the exact Bulk POST count and peak memory —
  an operator tool for sizing a deployment. Defaults to a fake sink (`NullSender`, nothing reaches
  Matomo); `--against=real` exercises the configured instance. `--hits`, `--driver`, `--batch`.
- **Worker recycling.** `matomo:work` gained `--max-time` and `--memory` (MB) so a supervisor can
  recycle a long-running drainer before it grows unbounded, mirroring `queue:work`.

### Changed

- **Bounded-memory file spool.** The `file` buffer driver now streams reads line by line, so
  counting or claiming never loads the whole spool into memory — only the claimed batch is held.

### Performance

- New gated `tests/Performance` budget suite (run with `composer test:performance`; excluded from
  the default gate) asserts a large spool drains fully, coalesces into exactly `ceil(N/size)` bulk
  POSTs, and stays within a memory budget.

## [0.13.0] - 2026-07-03

### Added

- **Release annotations.** Post notes to Matomo's free Annotations plugin — most usefully a
  deploy marker on your reports timeline. `MatomoAnnotations::add()` / `annotateRelease()`, the
  `matomo:annotate` command (with `--release`, gated by the `annotations.release` config so it's
  safe to run on every deploy), a `MatomoAnnotations::fake()` test double, and an `annotations`
  config block. Routed through the resilience reporter, so a failed annotation never breaks a deploy.

### Config

- New env key `MATOMO_ANNOTATE_RELEASES` (default `false`) enables `matomo:annotate --release`.

## [0.12.0] - 2026-07-03

### Added

- **Fluent report queries.** `MatomoReports::query('Module.method')` returns a builder for
  segments and the standard Matomo report filters — `->segment()`, `->sortBy()`, `->limit()`,
  `->offset()`, `->search()`, `->truncate()`, `->flat()`, `->expanded()`, `->showColumns()`,
  `->hideColumns()`, `->params()` — then `->get()` runs it through the same cache and resilience
  path as `get()`.
- **Segments.** A `Segment` builder composes Matomo segment definitions
  (`Segment::where('deviceType', '==', 'smartphone')->andWhere('visitCount', '>', 1)`), and a
  named-segment registry (`reporting.segments`) lets you reference saved segments by key.
- **Premium-plugin report adapters.** Thin, gracefully-degrading read helpers for licensed
  Matomo plugins: `abTests`, `funnelFlow`, `forms`, `media`, `cohorts`, `usersFlow` (each
  returns `null` when the plugin isn't installed).

## [0.11.0] - 2026-07-03

### Added

- **Custom Dimensions.** Attach Matomo Custom Dimensions to any hit. Client-side, map
  `js.custom_dimensions` (dimension id => value) to emit `setCustomDimension` on every
  page view (re-applied on SPA soft navigations). Server-side, decorate any hit with the
  new `CustomParameters` helper: `CustomParameters::for($hit)->dimension(1, 'plan:pro')`.
- **Raw parameter escape hatch.** `CustomParameters::param($key, $value)` sets any raw
  Tracking-API parameter the typed hits don't model (e.g. campaign `_rcn`/`_rck`), so you
  never have to drop down to a manual request.
- **Content Tracking.** Record content impressions and interactions. Client-side,
  `js.content_tracking` (`'all'` or `'visible'`) turns on automatic impression tracking
  (also re-scanned on SPA navigations). Server-side, `Matomo::contentImpression()` and
  `Matomo::contentInteraction()` record them explicitly.
- **Reporting helpers** for the new reports: `MatomoReports::customDimension($idDimension)`,
  `contentNames()` and `contentPieces()`.

## [0.10.0] - 2026-07-03

### Added

- **Page-performance control (Matomo's "Page Performance" report).** Matomo's page-performance metrics
  (network, server, transfer, DOM-processing, DOM-completion and on-load times) are collected
  automatically by the tracker on real page loads. Three new options give you control over them:
  - `js.performance` (default `true`) — set to `false` to stop the tracker from collecting page
    performance (emits `disablePerformanceTracking`).
  - `spa.performance` (default `true`) — on single-page/soft navigations the browser reports no new
    timings, so those rows stay empty. When your app measures them, expose them as
    `window.__matomoPerf = { net, srv, tfr, dm1, dm2, onl }` (milliseconds) and the SPA tracker forwards
    them for the next virtual page view. Harmless no-op until you populate that object (needs Matomo 4.5+).
  - `middleware.performance` (default `false`) — the page-view middleware stamps the server generation
    time (`pf_srv`) from the Laravel request duration, useful when tracking purely server-side.
- **AI-chatbot telemetry — self-hosted, without a Cloudflare Worker.** When an AI assistant fetches a
  page on a user's behalf it runs no JavaScript, so Matomo can only capture it server-side; Matomo's own
  integration for this is a Cloudflare Worker. This package can now send the same telemetry itself, at no
  edge cost:
  - `ai_chatbots.track` (default `false`) turns it on. Enable the `matomo.chatbots` middleware (or set
    `ai_chatbots.auto`) and incoming AI-assistant fetches are recorded as Matomo bot telemetry (`recMode`)
    — kept out of your human analytics and never creating a visit.
  - The recognized fetchers default to the narrow on-demand set Matomo surfaces (override via
    `ai_chatbots.user_agents`); `rec_mode` and `source` are configurable. Requires Matomo 5.8+.
  - `Matomo::aiChatbot($request)` records a fetch manually.
- **AI Assistants acquisition report.** Matomo's "AI Assistants" acquisition channel (human visits
  referred from AI assistants, Matomo 5.5+) is derived from the visit referrer, which the package already
  forwards — so it populates with no configuration. A guard now locks in that URL redaction never strips
  the referrer host this attribution depends on.

## [0.9.1] - 2026-07-01

### Changed

- The security policy now documents how dependencies are kept current — automated update pull requests
  paired with advisory alerts, each reviewed before it is merged.

## [0.9.0] - 2026-06-25

### Changed

- The package now supports **Laravel 12** alongside Laravel 13 — the minimum was lowered from `^13.0`
  to `^12.0 || ^13.0` (and `orchestra/testbench` to `^10.0 || ^11.0`). PHP stays at `^8.4`. Both
  Laravel lines are verified on PHP 8.4 and 8.5, so the support claim covers the whole matrix
  rather than only the newest combination.

## [0.8.1] - 2026-06-25

### Changed

- Refreshed the AI-crawler list with Cloudflare Radar's AI categories (AI_CRAWLER / AI_ASSISTANT /
  AI_SEARCH) on top of ai.robots.txt — adds Browserbase, KimiBot, Brandwatch, Claude, Element451Bot,
  AwarioSmartBot and more (150 tokens). The sync filters Radar to the AI categories client-side and
  drops substring-unsafe / non-AI entries.

## [0.8.0] - 2026-06-25

### Added

- Optional `matomo/device-detector` backstop: `DeviceDetectorBotDetector` wired through the
  `bots.detector` hook gives exhaustive, upstream-maintained bot detection across every category
  (search, social, SEO/marketing, monitoring, AI, …). Opt in with `composer require matomo/device-detector`.

### Changed

- Expanded the bundled AI-crawler list to 130+ tokens, regenerated from the canonical
  [ai.robots.txt](https://github.com/ai-robots-txt/ai.robots.txt) catalog (substring-unsafe
  entries filtered out) and kept current by a scheduled sync workflow that opens a review PR.
- Generic bot detection now also catches social link-preview agents (WhatsApp, SkypeUriPreview, vkShare).

## [0.7.0] - 2026-06-25

### Added

- Ecommerce tracking: `Matomo::ecommerceOrder()`, `ecommerceCartUpdate()` and `ecommerceView()`
  (with `EcommerceItem`/`EcommerceOrder`/`EcommerceCartUpdate`/`EcommerceView` value objects) map
  to Matomo's ecommerce parameters — `idgoal=0`, `ec_id`, `revenue`, `ec_st`/`ec_tx`/`ec_sh`/`ec_dt`,
  the `ec_items` JSON array, and the `_pks`/`_pkn`/`_pkc`/`_pkp` product-view params.
- Site-search build-out: `Matomo::searchFromRequest()` and `SiteSearch::fromRequest()` build a
  search straight from request query parameters; a `matomo.search` middleware auto-tracks searches
  on successful GET responses; no-result tracking via `siteSearch(..., count: 0)`.

## [0.6.0] - 2026-06-25

### Added

- Documentation: a "Scaling self-hosted Matomo (QueuedTracking)" guide — how Matomo's
  server-side QueuedTracking plugin composes with the package's batch/queue delivery so a
  busy self-hosted instance answers tracking requests in milliseconds and absorbs spikes.

## [0.5.0] - 2026-06-25

### Fixed

- Laravel Octane: the in-memory `array` batch buffer and the resolved `HitBuffer` are now
  request-scoped, so a long-lived Octane worker no longer carries buffered hits from one
  request into the next. All request-stateful services (tracker, reporting, GDPR, buffer)
  reset between requests; stateless ones stay shared. No change for classic FPM requests.

## [0.4.0] - 2026-06-25

### Added

- SPA / soft-navigation tracking (opt-in `spa.enabled`): the tracker snippet records a
  virtual page view on every client-side navigation that would otherwise be missed.
  Adapters — `livewire` (Livewire/WireKit `wire:navigate`), `inertia` (Inertia.js, covering
  Vue & React), and `generic` (History `pushState` + `popstate`). A `window.matomoTrackPageView()`
  helper is always exposed for manual/custom triggers. Tag Manager is left to handle SPA itself.

## [0.3.0] - 2026-06-25

### Added

- GDPR data-subject tools over Matomo's PrivacyManager API: `MatomoGdpr::forget()`
  erases (and `export()` exports) every visit matching a segment such as
  `userId==alice@example.com`, plus lower-level `findDataSubjects()`/`deleteVisits()`/
  `exportVisits()`. Calls are never cached and require an admin-access token.
- `matomo:forget {segment}` console command — finds the data subject, confirms, then
  erases (`--force` to skip the prompt, `--export` to export instead, `--site` to scope).
- A `DataSubjectForgotten` event (visit count + deletion counts) for audit trails, and a
  `MatomoGdpr::fake()` test double.

### Changed

- composer.json description and keywords now match the package's positioning
  (privacy-first, cookieless, Web Vitals, reporting API, bot detection) for Packagist
  discoverability.

## [0.2.0] - 2026-06-25

### Added

- Optional `js.host` (`MATOMO_JS_HOST`) to load `matomo.js` from a separate asset host
  — e.g. a Matomo Cloud CDN (`https://cdn.matomo.cloud/your-instance.matomo.cloud`) —
  while tracking stays on the main host; the host is also dns-prefetched.
- A dedicated "Matomo Cloud" guide in the README: host setup, the token requirement
  for the real visitor IP / hit time / geolocation, the CDN option, and end-to-end
  verification with `matomo:test` / `matomo:report`.

## [0.1.1] - 2026-06-25

### Fixed

- README: use a resolvable Packagist PHP-version badge
  (`packagist/dependency-v/.../php`); the previous `packagist/php-v` badge rendered
  "not found" on shields.io.

## [0.1.0] - 2026-06-25

First public release.

### Added

#### Tracking (server- and client-side)

- Server-side tracking via the `Matomo` facade: page views, events, site search,
  goals, downloads, outlinks, and pings.
- Cookieless visitor identification (a daily-rotating salted hash), with the real
  client IP and exact hit time forwarded when a token is configured.
- Three transmission modes — `sync`, `queue` (one bulk request per request), and
  `batch` (a cross-request buffer flushed in bulk) — switchable via `MATOMO_MODE`.
- Batch buffer drivers: `database`, `redis`, `file`, and `array`, drained by the
  scheduled `matomo:flush` or the `matomo:work` daemon.
- Automatic page-view middleware (`matomo.track`), with optional registration on the
  `web` group.
- Client-side `@matomoScript` and `@matomoOptOut` Blade directives: cookieless,
  consent modes, Do-Not-Track, heartbeat, a `<noscript>` pixel, a CSP nonce, and an
  optional Matomo Tag Manager container.
- Core Web Vitals (opt-in): a `@matomoWebVitals` directive beacons LCP/CLS/INP (and
  FCP/TTFB) to a server-side ingest route that records each as a Matomo event through
  the normal gate. Uses Google's `web-vitals` library (app-bundled or a configurable
  self-hosted URL); no third-party CDN is loaded by default.

#### Reporting (read side)

- Read-side Reporting API client via the `MatomoReports` facade: `get()` for a single
  method and `bulk()` for `API.getBulkRequest` batching, plus curated helpers
  (`visitsSummary`, `liveCounters`, `lastVisits`, `topPageUrls`, `topPageTitles`,
  `siteSearchKeywords`, `topReferrers`, `referrerTypes`, `countries`, `deviceTypes`,
  `browsers`, `goals`, `eventCategories`).
- Token-safe transport (form-encoded POST with `token_auth` in the body, forced
  HTTP/1.1, `{result: error}` envelope detection via `lastError()`), date-aware caching
  with a store-agnostic versioned `flushCache()` that never caches failures, the
  `matomo:report` command, and a `MatomoReports::fake()` test double.

#### Privacy & GDPR

- Configurable tracking gates (environment, authenticated state, Gate abilities,
  IP/CIDR ranges, route patterns, and a custom callable).
- Bot and AI-crawler detection (a maintained token list, generic signals, allow/deny
  lists, and a pluggable detector); bots are excluded by default.
- URL redaction: secrets and PII are stripped from tracked URLs before they reach
  Matomo (on by default, configurable query parameters and regex patterns).
- Server-side opt-out: the gate honors a first-party opt-out cookie
  (`MatomoAnalytics\Privacy\OptOut::enable()`/`disable()`).

#### Resilience

- Fail-safe delivery: never blocks the response, never throws into the app, with
  durable retries/backoff and throttled alerting that reports only after a configurable
  number of attempts.
- Dead-letter queue: a poison batch (HTTP 4xx) is parked at once and persistently
  failing batches are dead-lettered after `batch.max_attempts`, so one bad batch never
  blocks the queue; `matomo:replay` (`--list`, `--limit`, `--prune`) re-queues them,
  and a `HitsDeadLettered` event is emitted.
- Laravel events: `TrackingQueued`, `TrackingSent`, `TrackingFailed`, and
  `VisitorExcluded`.

#### Compatibility

- Support for both self-hosted Matomo and Matomo Cloud.
- Console commands: `matomo:install`, `matomo:test`, `matomo:flush`, `matomo:work`,
  `matomo:report`, `matomo:replay`.

<!--
Every version heading above is a reference link, and until 2026-08-27 none of them
resolved: thirty-one headings sat in square brackets with no definition anywhere in the
file, so GitHub and Packagist rendered a literal `[0.23.0]` where a compare link belonged.
Keep a Changelog's format assumes these definitions; the format was followed and the
half that makes it work was not.
-->

[Unreleased]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.32.0...HEAD
[0.32.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.31.1...v0.32.0
[0.31.1]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.31.0...v0.31.1
[0.31.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.30.0...v0.31.0
[0.30.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.29.1...v0.30.0
[0.29.1]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.29.0...v0.29.1
[0.29.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.28.8...v0.29.0
[0.28.8]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.28.7...v0.28.8
[0.28.7]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.28.6...v0.28.7
[0.28.6]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.28.5...v0.28.6
[0.28.5]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.28.4...v0.28.5
[0.28.4]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.28.3...v0.28.4
[0.28.3]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.28.2...v0.28.3
[0.28.2]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.28.1...v0.28.2
[0.28.1]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.28.0...v0.28.1
[0.28.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.27.2...v0.28.0
[0.27.2]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.27.1...v0.27.2
[0.27.1]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.27.0...v0.27.1
[0.27.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.26.0...v0.27.0
[0.26.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.25.0...v0.26.0
[0.25.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.24.0...v0.25.0
[0.24.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.23.0...v0.24.0
[0.23.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.22.0...v0.23.0
[0.22.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.21.0...v0.22.0
[0.21.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.20.0...v0.21.0
[0.20.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.19.2...v0.20.0
[0.19.2]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.19.1...v0.19.2
[0.19.1]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.19.0...v0.19.1
[0.19.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.17.0...v0.19.0
[0.18.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.17.0...v0.19.0
[0.17.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.16.0...v0.17.0
[0.16.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.15.0...v0.16.0
[0.15.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.14.3...v0.15.0
[0.14.3]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.14.2...v0.14.3
[0.14.2]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.14.1...v0.14.2
[0.14.1]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.14.0...v0.14.1
[0.14.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.13.0...v0.14.0
[0.13.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.12.0...v0.13.0
[0.12.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.11.0...v0.12.0
[0.11.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.10.0...v0.11.0
[0.10.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.9.1...v0.10.0
[0.9.1]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.9.0...v0.9.1
[0.9.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.8.1...v0.9.0
[0.8.1]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.8.0...v0.8.1
[0.8.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/pushery/matomo-analytics-for-laravel/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/pushery/matomo-analytics-for-laravel/releases/tag/v0.1.0
