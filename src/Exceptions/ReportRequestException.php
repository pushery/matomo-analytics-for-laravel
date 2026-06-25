<?php

declare(strict_types=1);

namespace MatomoAnalytics\Exceptions;

use RuntimeException;

/**
 * Read-side failure: a Matomo Reporting API call could not be completed or
 * returned an error envelope. Carried to the resilience reporter so read-side
 * outages follow the same throttled alerting policy as the tracking side.
 */
final class ReportRequestException extends RuntimeException {}
