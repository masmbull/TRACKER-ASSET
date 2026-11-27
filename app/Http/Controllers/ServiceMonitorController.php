<?php

namespace App\Http\Controllers;

use App\Services\ServiceHealthChecker;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * Infrastructure monitor: is every service this app depends on actually up.
 *
 * Admin-only (see the manage-users gate on the route). The checks reveal the
 * database driver, the mail host and the cache backend, which is deployment
 * detail rather than asset data, and a support or technician account has no
 * reason to see any of it.
 */
class ServiceMonitorController extends Controller
{
    public function __construct(private readonly ServiceHealthChecker $health) {}

    public function index(): View
    {
        return view('services.index', $this->safeReport());
    }

    /**
     * The monitor must remain renderable even when the environment it inspects
     * is broken. A probe failure is data ("down"), never an HTTP 500 - and a
     * fatal error inside the checker itself must still produce a page that says
     * the check could not be completed.
     */
    private function safeReport(): array
    {
        try {
            return $this->health->check();
        } catch (\Throwable $e) {
            return [
                'services' => [],
                'total' => 0,
                'healthy' => 0,
                'down' => 0,
                'skipped' => 0,
                'error' => 'Health check could not run: '.class_basename($e),
            ];
        }
    }

    /**
     * JSON for a scripted check or an uptime monitor.
     *
     * Answers 503 when anything is down, because a load balancer reads the
     * status code and a body saying "down" while returning 200 would report a
     * healthy instance.
     *
     * A skipped check does not produce a 503. It means the monitor could not
     * perform the check, not that it found a problem, and failing the instance
     * for it would turn an environment quirk into a fake outage. The `skipped`
     * count travels in the body so a consumer can still see that its view of the
     * system is partial.
     */
    public function json(): JsonResponse
    {
        $report = $this->safeReport();

        return response()->json($report, ($report['error'] ?? null) || ($report['down'] ?? 0) > 0 ? 503 : 200);
    }
}
