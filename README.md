# Codebase MCP Server

Stand-alone MCP server for [Codebase HQ](https://www.codebasehq.com/).

The MCP server provides the following tools:

* List tickets
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
php -d phar.readonly=0 build-phar.php
```

## Run

Start the server by executing the following command:

```bash
CODEBASE_USERNAME='<CODEBASE_USERNAME>' \
CODEBASE_API_KEY='<CODEBASE_API_KEY>' \
CODEBASE_PROJECT='<CODEBASE_PROJECT>' \
./build/codebase-mcp-server.phar
```

You can also set the environment variable `CODEBASE_API_URL` which defaults to
`https://api3.codebasehq.com/`.

## IDE Integration

Using *PhpStorm* configure a MCP server at _Tools > AI Assistant > Model
Context Protocol (MCP)_ with the following config:

```json
{
  "mcpServers": {
    "codebase": {
      "command": "codebase-mcp-server.phar",
      "env": {
        "CODEBASE_USERNAME": "",
        "CODEBASE_API_KEY": "",
        "CODEBASE_PROJECT": ""
      }
    }
  }
}
```

Also set the correct working directory (location of `codebase-mcp-server.phar`
file) and server level (project).
