# Codebase MCP Server

Stand-alone MCP server for [Codebase HQ](https://www.codebasehq.com/).

The MCP server provides the following tools:

* List projects
* Get project
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
