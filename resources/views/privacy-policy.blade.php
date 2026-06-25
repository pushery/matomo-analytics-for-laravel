{{-- Publishable privacy-policy snippet for cookieless Matomo analytics.
     Publish with: php artisan vendor:publish --tag=matomo-analytics-views
     Render with: @include('matomo-analytics::privacy-policy') --}}
<section class="matomo-analytics-privacy">
    <h2>{{ $heading ?? 'Web Analytics' }}</h2>
    <p>
        This website uses Matomo, a privacy-friendly open-source analytics platform,
        to measure how the site is used. Matomo is configured to run without cookies,
        anonymizes IP addresses before storing them, and never shares data with third
        parties. Because no personal data is stored and no cookies are set, no consent
        banner is required.
    </p>
</section>
