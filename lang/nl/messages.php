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
        'heading' => 'Websitestatistieken',
        'body' => 'Deze website gebruikt Matomo, een privacyvriendelijk open-source analyseplatform, om te meten hoe de site wordt gebruikt. Matomo is zo ingesteld dat het werkt zonder cookies, IP-adressen anonimiseert voordat ze worden opgeslagen en gegevens nooit deelt met derden. Omdat er geen persoonsgegevens worden opgeslagen en geen cookies worden geplaatst, is er geen toestemmingsbanner nodig.',
    ],

];
