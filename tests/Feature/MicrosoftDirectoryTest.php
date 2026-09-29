<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Microsoft 365 employee directory lookup (PRD Â§39) with faked Graph HTTP.
 *
 * The behaviour worth protecting is not "it calls Graph" - it is the things that
 * quietly go wrong: a search that 403s on every keystroke and looks like a broken
 * feature, a token endpoint hammered per character, a one-character query that
 * returns the entire company, and a query someone else can rewrite.
 */
class MicrosoftDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private function enableDirectory(): void
    {
        config([
            'services.entra.enabled' => true,
            'services.entra.directory' => true,
            'services.entra.client_id' => 'client-123',
            'services.entra.client_secret' => 'secret-123',
            'services.entra.tenant_id' => 'tenant-123',
        ]);
    }

    private function fakeDirectory(array $people): void
    {
        Http::fake([
            '*/oauth2/v2.0/token' => Http::response(['access_token' => 'app-token', 'expires_in' => 3600]),
            'https://graph.microsoft.com/v1.0/users*' => Http::response(['value' => $people]),
        ]);
    }

    public function test_it_returns_the_fields_the_prd_names(): void
    {
        $this->enableDirectory();
        $this->fakeDirectory([[
            'id' => 'object-1',
            'displayName' => 'Budi Santoso',
            'mail' => 'budi.santoso@mito.co.id',
            'department' => 'Finance',
            'jobTitle' => 'Analyst',
            'companyName' => 'PT Mito',
            'accountEnabled' => true,
        ]]);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson(route('users.directory', ['q' => 'Budi']));

        $response->assertOk();
        $person = $response->json('results.0');

        // PRD Â§39 names name, email, department, job title, company and status.
        $this->assertSame('Budi Santoso', $person['name']);
        $this->assertSame('budi.santoso@mito.co.id', $person['email']);
        $this->assertSame('Finance', $person['department']);
        $this->assertSame('Analyst', $person['job_title']);
        $this->assertSame('PT Mito', $person['company']);
        $this->assertTrue($person['is_active']);
    }

    /**
     * A disabled directory is a configuration, not a fault. A 500 would send an
     * admin hunting for a bug that does not exist.
     */
    public function test_a_disabled_directory_is_reported_not_thrown(): void
    {
        Http::fake();

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson(route('users.directory', ['q' => 'Budi']));

        $response->assertOk();
        $this->assertFalse($response->json('enabled'));
        $this->assertSame([], $response->json('results'));

        Http::assertNothingSent();
    }

    /**
     * The response carries the whole company's names, addresses, departments and
     * job titles. A support or technician account has no reason to read any of it.
     */
    public function test_the_directory_is_admin_only(): void
    {
        $this->enableDirectory();
        Http::fake();

        $this->actingAs(User::factory()->create(['role' => 'technician']))
            ->getJson(route('users.directory', ['q' => 'Budi']))
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'support']))
            ->getJson(route('users.directory', ['q' => 'Budi']))
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->getJson(route('users.directory', ['q' => 'Budi']))->assertUnauthorized();
    }

    /**
     * Graph needs ConsistencyLevel: eventual for $search and rejects the request
     * without it. The $select is the other half of the PRD rule about caching only
     * what is necessary: these are the only attributes this application claims.
     */
    public function test_the_graph_request_is_built_the_way_graph_requires(): void
    {
        $this->enableDirectory();
        $this->fakeDirectory([]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson(route('users.directory', ['q' => 'Budi']));

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'graph.microsoft.com/v1.0/users')) {
                return false;
            }

            $this->assertSame('eventual', $request->header('ConsistencyLevel')[0] ?? null);

            $decoded = urldecode($request->url());
            $this->assertStringContainsString('displayName:Budi', $decoded);
            $this->assertStringContainsString('mail:Budi', $decoded);
            $this->assertStringContainsString('department:Budi', $decoded);
            $this->assertStringContainsString('jobTitle:Budi', $decoded);

            return true;
        });
    }

    /**
     * A one-character query matches almost everyone, so the response would be
     * most of the tenant. Refusing it costs nothing.
     */
    public function test_a_one_character_query_never_reaches_microsoft(): void
    {
        $this->enableDirectory();
        Http::fake();

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson(route('users.directory', ['q' => 'B']));

        $response->assertOk();
        $this->assertSame([], $response->json('results'));
        Http::assertNothingSent();
    }

    /**
     * The search expression is built by concatenation, so the term must not be
     * able to close it. Graph's grammar cannot escape a quote inside a quoted
     * expression, so the only safe move is to drop it.
     */
    public function test_a_query_cannot_break_out_of_the_search_expression(): void
    {
        $this->enableDirectory();
        $this->fakeDirectory([]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson(route('users.directory', ['q' => 'Budi" or jobTitle:"CEO']));

        Http::assertSent(function ($request) {
            // assertSent runs this for every request, including the token one,
            // which has no $search and would read as an empty expression.
            if (! str_contains($request->url(), 'graph.microsoft.com/v1.0/users')) {
                return true;
            }

            // No urldecode: parse_str does it once, and decoding first turns the
            // separators in the URL into part of the query string.
            $expression = $this->searchExpressionFrom($request->url());

            // Pinned exactly. Counting occurrences does not work here: the term
            // itself contains "jobTitle:", so any count over field names is
            // polluted by the very input under test. What matters is the shape -
            // one clause per searched field, the caller's text confined to the
            // term, and no quote anywhere but the wrapper.
            $expected = '"'.implode(' or ', [
                'displayName:Budi or jobTitle:CEO',
                'mail:Budi or jobTitle:CEO',
                'userPrincipalName:Budi or jobTitle:CEO',
                'department:Budi or jobTitle:CEO',
                'jobTitle:Budi or jobTitle:CEO',
                'companyName:Budi or jobTitle:CEO',
            ]).'"';

            $this->assertSame($expected, $expression, 'the query changed the shape of the search expression');

            return true;
        });
    }

    /**
     * Typeahead fires on every keystroke. Without caching that is one token
     * request per character, against an endpoint Microsoft throttles hard enough
     * to start failing the whole feature.
     */
    public function test_the_application_token_is_reused_across_searches(): void
    {
        $this->enableDirectory();
        $this->fakeDirectory([]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->getJson(route('users.directory', ['q' => 'Budi']));
        $this->actingAs($admin)->getJson(route('users.directory', ['q' => 'Budiy']));
        $this->actingAs($admin)->getJson(route('users.directory', ['q' => 'Budiman']));

        $tokenCalls = 0;
        Http::assertSent(function ($request) use (&$tokenCalls) {
            if (str_contains($request->url(), 'oauth2/v2.0/token')) {
                $tokenCalls++;
            }

            return true;
        });

        $this->assertSame(1, $tokenCalls, 'three searches must share one application token');
        $this->assertSame('app-token', Cache::get('entra:app-token'));
    }

    public function test_a_fresh_test_starts_without_a_cached_token(): void
    {
        // Guards the guard: without this, the test above could be passing because
        // the token is pinned in the cache for the whole suite rather than being
        // fetched once per scenario.
        $this->assertNull(Cache::get('entra:app-token'));
    }

    /**
     * The most likely support ticket for this feature is a bare 403. Naming the
     * permission turns a mystery into a five-minute fix.
     */
    public function test_a_missing_graph_permission_is_explained_not_bare(): void
    {
        $this->enableDirectory();

        Http::fake([
            '*/oauth2/v2.0/token' => Http::response(['access_token' => 'app-token', 'expires_in' => 3600]),
            'https://graph.microsoft.com/v1.0/users*' => Http::response(
                ['error' => ['message' => 'Insufficient privileges to complete the operation.']],
                403
            ),
        ]);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson(route('users.directory', ['q' => 'Budi']));

        $response->assertStatus(502);
        $this->assertStringContainsString('User.Read.All', $response->json('error'));
        $this->assertSame([], $response->json('results'));
    }

    /**
     * A network failure is not a refusal. It is worth retrying, so it must not be
     * dressed up as a permissions problem the admin will go and change.
     */
    public function test_a_network_failure_asks_for_a_retry(): void
    {
        $this->enableDirectory();

        Http::fake([
            '*/oauth2/v2.0/token' => Http::response(['access_token' => 'app-token', 'expires_in' => 3600]),
            'https://graph.microsoft.com/v1.0/users*' => fn () => throw new ConnectionException('offline'),
        ]);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson(route('users.directory', ['q' => 'Budi']));

        $response->assertStatus(503);
        $this->assertStringContainsString('try again', strtolower($response->json('error')));
        $this->assertStringNotContainsString('User.Read.All', $response->json('error'));
    }

    /**
     * `common` is fine for interactive sign-in and meaningless for an application
     * token - there is no single directory to read.
     *
     * A misconfiguration has to arrive as a sentence, not a 500. An admin who
     * typed the wrong tenant should be told which variable is wrong, not handed a
     * stack trace and left guessing.
     */
    public function test_a_common_tenant_is_refused_before_calling_microsoft(): void
    {
        config([
            'services.entra.enabled' => true,
            'services.entra.directory' => true,
            'services.entra.client_id' => 'client-123',
            'services.entra.client_secret' => 'secret-123',
            'services.entra.tenant_id' => 'common',
        ]);

        Http::fake();

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson(route('users.directory', ['q' => 'Budi']));

        $response->assertStatus(502);
        $this->assertStringContainsString('ENTRA_TENANT_ID', $response->json('error'));
        Http::assertNothingSent();
    }

    /**
     * The search box is hidden when the feature is off rather than shown and
     * failing. Most installations will not have granted User.Read.All, and a box
     * that 403s on every keystroke reads as a broken page.
     */
    public function test_the_search_box_is_hidden_unless_the_directory_is_enabled(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('users.index'))
            ->assertOk()
            ->assertDontSee('Find in Microsoft 365')
            ->assertDontSee('m365-q');
    }

    public function test_the_search_box_appears_when_the_directory_is_enabled(): void
    {
        $this->enableDirectory();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('users.index'))
            ->assertOk()
            ->assertSee('Find in Microsoft 365')
            ->assertSee('m365-q')
            // The endpoint is embedded so the page makes no request until the
            // user actually types. @json escapes forward slashes, so the rendered
            // text is json_encode of the route, not the raw URL and not e() -
            // e() escapes &<>"' and leaves slashes alone.
            ->assertSee(json_encode(route('users.directory')), false);
    }

    /** Pull the raw $search value back out of a request URL. */
    private function searchExpressionFrom(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) ($query['$search'] ?? '');
    }
}
