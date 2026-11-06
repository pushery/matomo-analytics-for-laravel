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
        'heading' => 'Web-Analyse',
        'body' => 'Diese Website nutzt Matomo, eine datenschutzfreundliche Open-Source-Analyseplattform, um zu messen, wie die Website genutzt wird. Matomo ist so konfiguriert, dass es ohne Cookies arbeitet, keine Informationen von Ihrem Gerät ausliest, IP-Adressen vor dem Speichern anonymisiert und keine Daten an Dritte weitergibt. Da keine personenbezogenen Daten gespeichert, keine Cookies gesetzt und keine Informationen von Ihrem Gerät ausgelesen werden, ist kein Einwilligungsbanner erforderlich.',
    ],

];
