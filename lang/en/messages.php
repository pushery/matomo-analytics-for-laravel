<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Privacy-policy partial
    |--------------------------------------------------------------------------
    | The only user-facing prose this package renders. It is published with
    | `vendor:publish --tag=matomo-analytics-lang` and, once published, it is
    | YOURS: the paragraph states a legal conclusion ("no consent banner is
    | required") that holds for the SHIPPED configuration — cookieless,
    | anonymised IPs, no user id, no third-party sharing. Change any of those
    | and the sentence stops being true; change the sentence with it.
    */

    'privacy_policy' => [
        'heading' => 'Web Analytics',
        'body' => 'This website uses Matomo, a privacy-friendly open-source analytics platform, to measure how the site is used. Matomo is configured to run without cookies, anonymizes IP addresses before storing them, and never shares data with third parties. Because no personal data is stored and no cookies are set, no consent banner is required.',
    ],

];
