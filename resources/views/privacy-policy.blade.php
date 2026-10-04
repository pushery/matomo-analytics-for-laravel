{{-- Publishable privacy-policy snippet for cookieless Matomo analytics.
     Publish with: php artisan vendor:publish --tag=matomo-analytics-views
     Render with:  @include('matomo-analytics::privacy-policy')

     The prose lives in lang/<language>/messages.php and ships in seven languages, so
     a non-English site does not publish an English privacy paragraph. A regional
     locale such as pt_BR or de_AT reads its language. Override a single string by
     publishing the lang files, or pass $heading to change just the heading.

     Translation::line() rather than the global double-underscore translation helper, and
     fully qualified. That helper is declared in Illuminate\Foundation\helpers.php, which
     this package deliberately does not depend on, so a component-only install would fatal
     on this view. Translation::line() also reads the language of a regional locale, which
     Lang::get() never falls back to.

     Writing the helper's name here in full would trip the very guard that enforces this —
     LeanDependencyContractTest scans the shipped tree, and a Blade comment is not a PHP
     comment, so it cannot be stripped the way a docblock is.

     The paragraph asserts a legal conclusion that holds for the SHIPPED
     configuration — cookieless, anonymized IPs, no user id, nothing shared. If
     you change those, change the text. --}}
<section class="matomo-analytics-privacy">
    <h2>{{ $heading ?? \MatomoAnalytics\Support\Translation::line('matomo-analytics::messages.privacy_policy.heading') }}</h2>
    <p>
        {{ \MatomoAnalytics\Support\Translation::line('matomo-analytics::messages.privacy_policy.body') }}
    </p>
</section>
