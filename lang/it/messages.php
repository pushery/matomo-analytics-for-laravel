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
        'heading' => 'Statistiche del sito',
        'body' => 'Questo sito utilizza Matomo, una piattaforma di analisi open source rispettosa della privacy, per misurare come viene usato il sito. Matomo è configurato per funzionare senza cookie, anonimizza gli indirizzi IP prima di memorizzarli e non condivide mai i dati con terze parti. Poiché non vengono memorizzati dati personali né installati cookie, non è necessario alcun banner di consenso.',
    ],

];
