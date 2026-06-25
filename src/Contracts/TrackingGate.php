<?php

declare(strict_types=1);

namespace MatomoAnalytics\Contracts;

use Illuminate\Http\Request;
use MatomoAnalytics\Gates\GateDecision;
use MatomoAnalytics\Tracking\Hit;

interface TrackingGate
{
    public function decide(Request $request, Hit $hit): GateDecision;
}
