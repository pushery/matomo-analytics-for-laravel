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
        'heading' => 'Análise do site',
        'body' => 'Este site utiliza o Matomo, uma plataforma de análise de código aberto que respeita a privacidade, para medir como o site é utilizado. O Matomo está configurado para funcionar sem cookies, anonimiza os endereços IP antes de os armazenar e nunca partilha dados com terceiros. Como não são armazenados dados pessoais nem instalados cookies, não é necessário qualquer banner de consentimento.',
    ],

];
