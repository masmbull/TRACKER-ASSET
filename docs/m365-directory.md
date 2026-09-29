# Microsoft 365 Directory — TRACKER-ASSET

Employee lookup in the tenant directory, per PRD §39. Off by default.

## What it is

`GET /users/directory?q=budi` searches Microsoft 365 and returns the six fields
the PRD names — name, email, department, job title, company, status — using the
column names this application's own `users` table already uses, so a result can
prefill a form without translation.

Admin-only, on the same `manage-users` gate as the rest of user administration.
The response is the whole company's names, addresses, departments and job titles,
which is why a support or technician account has no business calling it.

## Enabling it

Two switches, because they are different decisions:

| Setting | Meaning |
|---|---|
| `ENTRA_ENABLED` | Entra is in use for sign-in at all |
| `ENTRA_DIRECTORY` | directory search specifically |

An installation can use Entra purely to sign people in and keep its user list
local. Turning the search box on without the tenant permission behind it produces
a box that 403s on every keystroke, which reads as a broken page.

Behind the second switch, in the Azure app registration:

1. **API permissions → Microsoft Graph → Application permissions → `User.Read.All`**
2. Grant admin consent.
3. Set `ENTRA_TENANT_ID` to a real tenant id.

`common` works for sign-in and is meaningless here: there is no single directory
to read. The app refuses it before sending anything, and names the variable.

The application permission is a genuinely broader grant than sign-in, which only
ever needs the delegated `User.Read` scope. That is why it is a separate switch
rather than implied by `ENTRA_ENABLED`.

## Why a second grant

Sign-in uses the authorization-code flow and reads `/me`. A delegated `User.Read`
token can only read the user who signed in — asking it for the directory returns
403, or quietly a list of one. Directory-wide reads need the client-credentials
grant, so `EntraOAuthService::appToken()` sits alongside `exchangeCode()`.

The application token is cached until a minute before it expires. Search is
typeahead, so an uncached call would hit the token endpoint on every keystroke
against an endpoint Microsoft throttles hard enough to fail the whole feature.

## What is deliberately not here

- **No mirroring.** The PRD says "cache only what is necessary for relational
  database references". The directory is not copied into this database. A company
  with a few thousand staff should not end up with a few thousand rows here
  because someone opened a search box. The only thing cached is the token.
- **No `$select` beyond the six fields plus `id`.** That is what keeps the rest of
  the profile from crossing the wire.
- **No one-character queries.** Graph matches nearly everything at one character
  and would return most of the tenant.

## Query handling

Graph's `$search` is not free text over all fields. It needs a field prefix per
term and `ConsistencyLevel: eventual`, or the request is rejected with a bare 400
that looks like a permissions problem.

The expression is built by concatenation, so a quote typed into the box is
**stripped** rather than escaped — Graph's grammar cannot escape a quote inside a
quoted expression, so dropping it is the only safe option. A term of
`Budi" or jobTitle:"CEO` becomes the plain text `Budi or jobTitle:CEO`, repeated
into each clause; it cannot introduce a clause of its own.
`MicrosoftDirectoryTest` pins the resulting expression exactly, because counting
field names would be polluted by the very input under test.

## Failure messages

The point of these is that nobody has to guess:

| Situation | Response |
|---|---|
| Feature off | `200` with `enabled: false`; the box is not rendered at all |
| Graph `401` / `403` | `502` naming `User.Read.All` and admin consent |
| Graph `429` | `502` saying it is rate-limited, retry shortly |
| Network unreachable | `503` asking for a retry, and *not* blaming permissions |
| `common` tenant | `502` naming `ENTRA_TENANT_ID`, before any request is sent |

The network case is kept separate from the refusal case on purpose. Dressing a
network blip up as a permissions problem sends an admin off to change something
that was never wrong.

## Files

| Path | Role |
|---|---|
| `app/Services/MicrosoftDirectory.php` | the search, and its two Graph gotchas |
| `app/Services/EntraOAuthService.php` | both grants, and why they differ |
| `app/Http/Controllers/MicrosoftDirectoryController.php` | the endpoint and its statuses |
| `tests/Feature/MicrosoftDirectoryTest.php` | 14 tests, Graph faked — nothing contacts Microsoft |
