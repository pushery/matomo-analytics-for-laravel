<?php

declare(strict_types=1);

namespace MatomoAnalytics\Bots;

/** What sent a request, as UserAgentClassifier reads it from the User-Agent. */
enum UserAgentKind: string
{
    /** A person's browser. */
    case Human = 'human';

    /** A crawler, a script or any other client the bot detector calls a bot. */
    case Bot = 'bot';

    /** A bot that fetches a page because a person shared its link, to show a preview of it. */
    case LinkPreview = 'link-preview';
}
