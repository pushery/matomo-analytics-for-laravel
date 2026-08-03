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
        'heading' => 'Statistiques du site',
        'body' => 'Ce site utilise Matomo, une plateforme d’analyse open source respectueuse de la vie privée, pour mesurer la façon dont le site est utilisé. Matomo est configuré pour fonctionner sans cookies, anonymise les adresses IP avant de les enregistrer et ne partage jamais de données avec des tiers. Comme aucune donnée personnelle n’est conservée et qu’aucun cookie n’est déposé, aucune bannière de consentement n’est nécessaire.',
    ],

];
