<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Privacy-policy partial
    |--------------------------------------------------------------------------
    | The only user-facing prose this package renders. It is published with
    | `vendor:publish --tag=matomo-analytics-lang` and, once published, it is
    | yours: the paragraph states a legal conclusion ("no consent banner is
    | required") that holds for the shipped configuration — cookieless, no
    | device features read, anonymized IPs, no user id, no third-party
    | sharing. Change any of those and the sentence stops being true; change
    | the sentence with it.
    */

    'privacy_policy' => [
        'heading' => 'Web Analytics',
        'body' => 'This website uses Matomo, a privacy-friendly open-source analytics platform, to measure how the site is used. Matomo is configured to run without cookies, reads no information from your device, anonymizes IP addresses before storing them, and never shares data with third parties. Because no personal data is stored, no cookies are set and nothing is read from your device, no consent banner is required.',
    ],

];
