{{-- Publishable privacy-policy snippet for cookieless Matomo analytics.
     Publish with: php artisan vendor:publish --tag=matomo-analytics-views
     Render with:  @include('matomo-analytics::privacy-policy')

     The prose lives in lang/<locale>/messages.php and ships in seven locales, so
     a non-English site does not publish an English privacy paragraph. Override a
     single string by publishing the lang files, or pass $heading to change just
     the heading.

     Lang::get() rather than the global double-underscore translation helper, and fully
     qualified rather than through the alias. That helper is declared in
     Illuminate\Foundation\helpers.php, which this package deliberately does not depend on,
     so a component-only install would fatal on this view. The `Lang` alias itself comes
     from the host application's config, which a lean install need not have either.

     Writing the helper's name here in full would trip the very guard that enforces this —
     LeanDependencyContractTest scans the shipped tree, and a Blade comment is not a PHP
     comment, so it cannot be stripped the way a docblock is.

     The paragraph asserts a legal conclusion that holds for the SHIPPED
     configuration — cookieless, anonymized IPs, no user id, nothing shared. If
     you change those, change the text. --}}
<section class="matomo-analytics-privacy">
    <h2>{{ $heading ?? \Illuminate\Support\Facades\Lang::get('matomo-analytics::messages.privacy_policy.heading') }}</h2>
    <p>
        {{ \Illuminate\Support\Facades\Lang::get('matomo-analytics::messages.privacy_policy.body') }}
    </p>
</section>
