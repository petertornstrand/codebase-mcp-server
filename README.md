# Codebase MCP Server

Stand-alone MCP server for [Codebase HQ](https://www.codebasehq.com/).

The MCP server provides the following tools:

* List projects
* Get project
* List tickets (one project, 20 per page)
* List my tickets (across all active projects in one call)
* Find inactive projects (projects you are assigned to without activity for 12+ months)
* Unassign from projects (remove yourself from inactive projects; dry run unless confirmed)
* Get ticket
* Get ticket notes
* Get ticket statuses
* Get ticket priorities
* Get ticket categories
* Get ticket types
* Create ticket
* Update ticket
* Get milestones
* Get project activity
* Get project users

## Build

To build the server, execute the following command from the project root:

```bash
BUILD_DIR='./build' \
php -d phar.readonly=0 build-phar.php
```

## Run

Start the server by executing the following command:

```bash
CODEBASE_USERNAME='<CODEBASE_USERNAME>' \
CODEBASE_API_KEY='<CODEBASE_API_KEY>' \
./build/codebase-mcp-server.phar
```

Optional environment variables:

* `CODEBASE_PROJECT`: The permalink of a project. Settings this variable means
   you will not have to pass the project as an argument to the tools.
* `CODEBASE_API_URL`: If you for some reason want to use another base URL than
  the default; `https://api3.codebasehq.com`.


## IDE Integration

Using *PhpStorm* configure a MCP server at _Tools > AI Assistant > Model
Context Protocol (MCP)_ with the following config:

```json
{
  "mcpServers": {
    "codebase": {
      "command": "./bin/codebase-mcp-server.phar",
      "env": {
        "CODEBASE_USERNAME": "<CODEBASE_USERNAME>",
        "CODEBASE_API_KEY": "<CODEBASE_API_KEY>"
      }
    }
  }
}
```

Also set the correct working directory (location of this repository) and server
level (project or global).


## Remote (HTTP) server

The server can run as a remote MCP server over Streamable HTTP, for use from
Claude.ai, Claude Desktop and Claude Code.

* Users sign in with **Google Workspace (SAML)**, restricted to one email
  domain.
* On first use they enter their **personal Codebase username and API key**.
  The key is stored encrypted (XChaCha20-Poly1305) in a SQLite database.
* Clients get OAuth 2.1 tokens issued by this server (dynamic client
  registration, PKCE required, refresh token rotation). Every Codebase API call
  is made with the signed-in user's own credentials.

Endpoints: `/mcp`, `/authorize`, `/token`, `/register`, `/revoke`,
`/saml/acs`, `/saml/metadata`, `/setup` and the `/.well-known/oauth-*`
metadata documents.

### Google Workspace setup

In the Admin console, add a custom SAML app (Apps > Web and mobile apps) and
use these service provider values (replace the host with your own):

| Field | Value |
|---|---|
| Entity ID | `https://codebase-mcp.hpns.dev` |
| ACS URL | `https://codebase-mcp.hpns.dev/saml/acs` |
| Name ID format | `EMAIL` |
| Name ID | Primary email |
| Signed response | on |

Turn the app on for the users or groups that should have access. Copy the
IdP SSO URL, entity ID and certificate into `config.php`. The server also
rejects any email outside `allowed_domain`.

### Deploy

1. Install dependencies without dev packages and upload the project,
   including `vendor/`:

   ```bash
   composer install --no-dev --optimize-autoloader
   ```

2. Point the web root of the domain at `public/`. Requires PHP 8.3+ with the
   `curl`, `dom`, `openssl`, `pdo_sqlite` and `sodium` extensions.
3. Copy `config.example.php` to `config.php` and fill it in. Use a `data_dir`
   outside the web root; the app creates it with `0700` permissions. Generate
   the encryption key once and keep it safe; without it, stored API keys can
   no longer be read and users must reconnect:

   ```bash
   php -r 'echo base64_encode(random_bytes(32)), "\n";'
   ```

4. Serve over HTTPS only.

### Connect

* **Claude.ai / Claude Desktop:** Settings > Connectors > Add custom
  connector, URL `https://codebase-mcp.hpns.dev/mcp`.
* **Claude Code:**

  ```bash
  claude mcp add --transport http codebase https://codebase-mcp.hpns.dev/mcp
  ```

  then run `/mcp` in Claude Code to authenticate.

### Offboarding

Access tokens last 1 hour and refresh tokens 30 days, and the identity
provider is only consulted at sign-in. To cut off someone immediately, delete
them in Google **and** run:

```bash
php bin/revoke-user.php someone@happiness.se
```

### Development

The project includes a [DDEV](https://ddev.com) setup:

```bash
ddev start
ddev composer install
ddev exec vendor/bin/phpunit
```

The tests run the full OAuth and SAML flow against a fake identity provider
that signs responses with a throwaway key.

### Allowing destructive actions

Tools that remove access or data are **off by default**. Today that is
`unassign_from_projects`. To allow them, set this in `config.php`:

```php
'allow_destructive' => true,
```

It must be exactly `true` or `false`. Anything else, including the string
`'false'`, is a configuration error and the server refuses to start, so a typo
can never switch the tools on. While it is off the tools are hidden from
clients, calling one is refused without touching Codebase, and the read-only
`find_inactive_projects` keeps working but says that removal is disabled.

When running the local command line server instead, set the environment
variable `CODEBASE_ALLOW_DESTRUCTIVE=1` (or `true`). Any other value leaves
the tools off.

Creating and updating tickets add data but remove none, so they are not
treated as destructive.

### Finding and leaving inactive projects

`find_inactive_projects` (read-only) lists the active projects you are assigned
to where you have had no activity for at least the given number of months
(minimum 12). You count as active if you have an open ticket assigned, a ticket
assigned to you updated within the period, or an event by you in the project's
activity feed. A project is only reported as inactive when all of that was
checked completely; anything uncertain (an error, a very busy feed, running out
of time) is listed as `undetermined`.

`unassign_from_projects` removes **only you** from the projects you name. It
requires `allow_destructive` (see above):

* Without `confirm: true` it is a dry run and changes nothing.
* Each project is checked again and is only changed if it is still inactive.
* Codebase has no "remove user" call. The only way is to replace the project's
  whole list of users, so the server posts everyone else back unchanged, reads
  the list again to verify, and restores the original list if anything differs.
  If even the restore fails, the result says `RESTORE FAILED` and includes the
  original user ids so an administrator can fix it.
* You must be allowed to change project users (an account administrator). It
  never removes the only remaining user of a project.

Try it on one low-stakes project first. Undoing a removal needs an account
administrator.

