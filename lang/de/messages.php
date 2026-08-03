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
    | anonymized IPs, no user id, no third-party sharing. Change any of those
    | and the sentence stops being true; change the sentence with it.
    */

    'privacy_policy' => [
        'heading' => 'Web-Analyse',
        'body' => 'Diese Website nutzt Matomo, eine datenschutzfreundliche Open-Source-Analyseplattform, um zu messen, wie die Website genutzt wird. Matomo ist so konfiguriert, dass es ohne Cookies arbeitet, IP-Adressen vor dem Speichern anonymisiert und keine Daten an Dritte weitergibt. Da keine personenbezogenen Daten gespeichert und keine Cookies gesetzt werden, ist kein Einwilligungsbanner erforderlich.',
    ],

];
