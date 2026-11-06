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
        'heading' => 'Analítica web',
        'body' => 'Este sitio web utiliza Matomo, una plataforma de analítica de código abierto respetuosa con la privacidad, para medir cómo se usa el sitio. Matomo está configurado para funcionar sin cookies, no lee información de su dispositivo, anonimiza las direcciones IP antes de almacenarlas y nunca comparte datos con terceros. Como no se almacenan datos personales, no se instalan cookies y no se lee información de su dispositivo, no se requiere ningún banner de consentimiento.',
    ],

];
