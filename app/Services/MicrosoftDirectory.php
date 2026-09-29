<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Employee directory lookup in Microsoft 365 (PRD §39).
 *
 * The PRD names Entra/Graph as the source for employee directory information and
 * asks for lookup by name, email, department, job title, company and status. This
 * reads those six things and nothing else.
 *
 * "Cache only what is necessary for relational database references" is taken
 * literally: this returns results and stores nothing. The tenant directory is not
 * mirrored - a company with a few thousand staff should not end up with a few
 * thousand rows in this database because someone opened a search box. The only
 * thing cached is the OAuth token.
 */
class MicrosoftDirectory
{
    private const GRAPH_USERS = 'https://graph.microsoft.com/v1.0/users';

    /** The only attributes this application asks for. */
    private const SELECT = 'id,displayName,mail,userPrincipalName,department,jobTitle,companyName,accountEnabled';

    /** Ceiling on one page. Typeahead does not need more. */
    private const MAX_RESULTS = 25;

    public function __construct(private readonly EntraOAuthService $entra) {}

    public function enabled(): bool
    {
        return (bool) config('services.entra.enabled') && (bool) config('services.entra.directory');
    }

    /**
     * Graph's $search is not free text over all fields: it needs a field prefix
     * per term and it needs ConsistencyLevel: eventual, or the request is
     * rejected. Both are easy to omit and produce a bare 400 that reads like a
     * permissions problem.
     *
     * @return array<int, array{id:string,name:string,email:string,department:?string,job_title:?string,company:?string,is_active:bool}>
     */
    public function search(string $query, int $limit = 10): array
    {
        $query = trim($query);

        // Below two characters Graph matches nearly everything and hands back a
        // near-complete tenant: slow, and more employee data than anyone typing
        // into a search box has asked for.
        if (mb_strlen($query) < 2) {
            return [];
        }

        $response = Http::withToken($this->entra->appToken())
            ->withHeaders(['ConsistencyLevel' => 'eventual'])
            ->get(self::GRAPH_USERS, [
                '$search' => $this->searchExpression($query),
                '$select' => self::SELECT,
                '$top' => max(1, min($limit, self::MAX_RESULTS)),
            ]);

        if (! $response->successful()) {
            // Log the status, not the query: the query is whatever an employee
            // typed and does not belong in a log file.
            Log::warning('Microsoft directory search failed', [
                'status' => $response->status(),
                'error' => $response->json('error.message'),
            ]);

            throw new DirectorySearchException($this->explain($response->status()));
        }

        return collect($response->json('value') ?? [])
            ->map(fn (array $person): array => $this->normalise($person))
            ->all();
    }

    /**
     * Quotes are stripped rather than escaped: Graph's search grammar cannot
     * escape a quote inside a quoted expression, so a term containing one can
     * only be dropped or left malformed - and malformed input here means the
     * request stops being the query the user typed.
     */
    private function searchExpression(string $query): string
    {
        $term = str_replace('"', '', $query);
        $fields = ['displayName', 'mail', 'userPrincipalName', 'department', 'jobTitle', 'companyName'];

        return '"'.implode(' or ', array_map(fn (string $f): string => $f.':'.$term, $fields)).'"';
    }

    /** Maps a Graph user onto the fields PRD §39 lists, reusing local column names. */
    private function normalise(array $person): array
    {
        return [
            'id' => (string) ($person['id'] ?? ''),
            'name' => (string) ($person['displayName'] ?? ''),
            'email' => (string) ($person['mail'] ?? $person['userPrincipalName'] ?? ''),
            'department' => $person['department'] ?? null,
            'job_title' => $person['jobTitle'] ?? null,
            'company' => $person['companyName'] ?? null,
            // Absent means unknown, and unknown is treated as findable: a person
            // is easier to hide from this search than to add later, and an admin
            // who needs them can still see the record and act on it.
            'is_active' => (bool) ($person['accountEnabled'] ?? true),
        ];
    }

    /**
     * A bare "403" from this feature is the most likely support question, and the
     * answer is almost always the application permission rather than the user.
     */
    private function explain(int $status): string
    {
        return match ($status) {
            401, 403 => 'Microsoft 365 directory access is not granted for this application. '
                .'Check that the app registration has the User.Read.All application permission and admin consent.',
            429 => 'Microsoft 365 is rate-limiting directory searches. Try again shortly.',
            default => 'Microsoft 365 directory search is unavailable (HTTP '.$status.').',
        };
    }
}

/**
 * A lookup the server answered and then refused.
 *
 * Separate from a transport failure on purpose: a connection exception means the
 * network is down and "try again" is the entire message, whereas this means the
 * request was understood and denied, and retrying will not help.
 */
class DirectorySearchException extends \RuntimeException {}
