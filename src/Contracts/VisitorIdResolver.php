<?php

declare(strict_types=1);

namespace MatomoAnalytics\Contracts;

use Illuminate\Http\Request;

interface VisitorIdResolver
{
    /**
     * Resolve a 16-character lowercase hex Matomo visitor id for the request.
     */
    public function resolve(Request $request): string;
}
