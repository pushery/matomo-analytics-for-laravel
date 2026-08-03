{{-- Publishable privacy-policy snippet for cookieless Matomo analytics.
     Publish with: php artisan vendor:publish --tag=matomo-analytics-views
     Render with:  @include('matomo-analytics::privacy-policy')

     The prose lives in lang/<locale>/messages.php and ships in seven locales, so
     a non-English site does not publish an English privacy paragraph. Override a
     single string by publishing the lang files, or pass $heading to change just
     the heading.

     The paragraph asserts a legal conclusion that holds for the SHIPPED
     configuration — cookieless, anonymized IPs, no user id, nothing shared. If
     you change those, change the text. --}}
<section class="matomo-analytics-privacy">
    <h2>{{ $heading ?? __('matomo-analytics::messages.privacy_policy.heading') }}</h2>
    <p>
        {{ __('matomo-analytics::messages.privacy_policy.body') }}
    </p>
</section>
