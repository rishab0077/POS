<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Middleware;

class TrustHosts extends Middleware
{
    /**
     * Get the host patterns that should be trusted.
     *
     * @return array<int, string|null>
     */
    public function hosts()
    {
        $configuredHosts = collect([
            ...config('security.hosts.public', []),
            ...config('security.hosts.private', []),
            ...config('security.hosts.print', []),
        ])->filter()->map(fn (string $host) => '^' . preg_quote($host, '/') . '$');

        return $configuredHosts
            ->push($this->allSubdomainsOfApplicationUrl())
            ->unique()
            ->values()
            ->all();
    }
}
