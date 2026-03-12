<?php

namespace petertornstrand;

/**
 * Class CodebaseMCPServer
 *
 * Implements an MCP (Model Context Protocol) server for interacting with the Codebase API.
 * This server allows MCP-compatible clients (like AI assistants) to list, get, create,
 * and update tickets, as well as access other project resources in Codebase HQ.
 */
class CodebaseMCPServer {

  /**
   * Initializes the Codebase MCP Server.
   *
   * @param string $username
   *   The Codebase username (often in the format 'account/username').
   * @param string $apiKey
   *   The API key for authentication.
   * @param ?string $project
   *   The short name/permalink of the project.
   * @param ?string $baseUrl
   *   The API base URL.
   */
  public function __construct(
    private string $username,
    private string $apiKey,
    private ?string $project = null,
    private ?string $baseUrl = null,
  ) {
    // If no environment variable for API base URL is set use a default.
    if ($baseUrl) {
      $this->baseUrl = rtrim($baseUrl, '/');
    }
    else {
      $this->baseUrl = 'https://api3.codebasehq.com';
    }
  }

  /**
   * Main loop to handle MCP requests from stdin and respond to stdout.
   *
   * Listens for JSON-RPC messages and dispatches them to handleRequest.
   */
  public function run(): void {
    $stdin = fopen('php://stdin', 'r');
    while ($line = fgets($stdin)) {
      $request = json_decode($line, true);
      if (!$request) continue;

      $response = $this->handleRequest($request);
      echo json_encode($response) . "\n";
    }
  }

  /**
   * Handles an incoming MCP/JSON-RPC request.
   *
   * @param array $request
   *   The decoded JSON request.
   *
   * @return array
   *   The JSON-RPC response array.
   */
  private function handleRequest(array $request): array {
    $method = $request['method'] ?? '';
    $params = $request['params'] ?? [];
    $project = $params['project'] ?? $this->project ?? '';
    $id = $request['id'] ?? null;

    try {
      $result = match ($method) {
        'initialize' => $this->initialize(),
        'tools/list' => $this->listTools(),
        'tools/call' => $this->callTool($project, $params['name'] ?? '', $params['arguments'] ?? []),
        'resources/list' => $this->listResources(),
        'resources/read' => $this->readResource($params['uri'] ?? ''),
        default => throw new \Exception(sprintf('Method not found: %s', $method)),
      };

      return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => $result,
      ];
    } catch (\Exception $e) {
      return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'error' => [
          'code' => -32603,
          'message' => $e->getMessage(),
        ],
      ];
    }
  }

  /**
   * Handles the 'initialize' method.
   *
   * Provides server information and capabilities (tools and resources).
   *
   * @return array
   *   Initial server metadata.
   */
  private function initialize(): array {
    return [
      'protocolVersion' => '2024-11-05',
      'capabilities' => [
        'tools' => (object)[],
        'resources' => (object)[],
      ],
      'serverInfo' => [
        'name' => 'codebase-hq-mcp-server',
        'version' => '1.0.0',
      ]
    ];
  }

  /**
   * Lists all available tools provided by this MCP server.
   *
   * @return array
   *   A list of tool definitions including names, descriptions, and input schemas.
   */
  private function listTools(): array {
    return [
      'tools' => [
        [
          'name' => 'list_projects',
          'description' => 'List projects',
          'inputSchema' => [
            'type' => 'object',
            'properties' => (object)[],
          ],
        ],
        [
          'name' => 'get_project',
          'description' => 'Get a specific project',
          'inputSchema' => [
            'type' => 'object',
            'properties' => [
              'project' => ['type' => 'string', 'description' => 'The project permalink (e.g., my-project).'],
            ],
          ],
        ],
        [
          'name' => 'list_tickets',
          'description' => 'List tickets in the project',
          'inputSchema' => [
            'type' => 'object',
            'properties' => [
              'project' => ['type' => 'string', 'description' => 'The project permalink (e.g., my-project).'],
              'query' => ['type' => 'string', 'description' => 'Search query (e.g., status:open, assignee:username, priority:high).'],
            ],
          ],
        ],
        [
          'name' => 'get_ticket',
          'description' => 'Get details of a specific ticket',
          'inputSchema' => [
            'type' => 'object',
            'properties' => [
              'project' => ['type' => 'string', 'description' => 'The project permalink (e.g., my-project).'],
              'ticket_id' => ['type' => 'integer'],
            ],
            'required' => ['ticket_id'],
          ],
        ],
        [
          'name' => 'get_ticket_notes',
          'description' => 'Get notes (comments) for a specific ticket',
          'inputSchema' => [
            'type' => 'object',
            'properties' => [
              'project' => ['type' => 'string', 'description' => 'The project permalink (e.g., my-project).'],
              'ticket_id' => ['type' => 'integer', 'description' => 'The ID of the ticket'],
            ],
            'required' => ['ticket_id'],
          ],
        ],
        [
          'name' => 'get_ticket_statuses',
          'description' => 'Get all ticket statuses for the project',
          'inputSchema' => [
            'type' => 'object',
            'properties' => [
              'project' => ['type' => 'string', 'description' => 'The project permalink (e.g., my-project).'],
            ],
          ],
        ],
        [
          'name' => 'get_ticket_priorities',
          'description' => 'Get all ticket priorities for the project',
          'inputSchema' => [
            'type' => 'object',
            'properties' => [
              'project' => ['type' => 'string', 'description' => 'The project permalink (e.g., my-project).'],
            ],
          ],
        ],
        [
          'name' => 'get_ticket_categories',
          'description' => 'Get all ticket categories for the project',
          'inputSchema' => [
            'type' => 'object',
            'properties' => [
              'project' => ['type' => 'string', 'description' => 'The project permalink (e.g., my-project).'],
            ],
          ],
        ],
        [
          'name' => 'get_ticket_types',
          'description' => 'Get all ticket types for the project',
          'inputSchema' => [
            'type' => 'object',
            'properties' => [
              'project' => ['type' => 'string', 'description' => 'The project permalink (e.g., my-project).'],
            ],
          ],
        ],
        [
          'name' => 'create_ticket',
          'description' => 'Create a new ticket in the project',
          'inputSchema' => [
            'type' => 'object',
            'properties' => [
              'project' => ['type' => 'string', 'description' => 'The project permalink (e.g., my-project).'],
              'summary' => ['type' => 'string', 'description' => 'The title of the ticket'],
              'description' => ['type' => 'string', 'description' => 'The detailed description of the ticket'],
              'status' => ['type' => 'string', 'description' => 'Status name (e.g., New, Open)'],
              'priority' => ['type' => 'string', 'description' => 'Priority name (e.g., Low, Normal, High)'],
              'category' => ['type' => 'string', 'description' => 'Category name'],
              'assignee' => ['type' => 'string', 'description' => 'Username of the assignee'],
            ],
            'required' => ['summary'],
          ],
        ],
        [
          'name' => 'update_ticket',
          'description' => 'Update a ticket (add a note, change status, etc.)',
          'inputSchema' => [
            'type' => 'object',
            'properties' => [
              'project' => ['type' => 'string', 'description' => 'The project permalink (e.g., my-project).'],
              'ticket_id' => ['type' => 'integer', 'description' => 'The ID of the ticket to update'],
              'content' => ['type' => 'string', 'description' => 'The comment or note text'],
              'status' => ['type' => 'string', 'description' => 'New status name'],
              'priority' => ['type' => 'string', 'description' => 'New priority name'],
              'category' => ['type' => 'string', 'description' => 'New category name'],
              'assignee' => ['type' => 'string', 'description' => 'Username or full name of the new assignee'],
              'summary' => ['type' => 'string', 'description' => 'New summary/title'],
            ],
            'required' => ['ticket_id'],
          ],
        ],
        [
          'name' => 'get_milestones',
          'description' => 'Get all milestones for the project',
          'inputSchema' => [
            'type' => 'object',
            'properties' => [
              'project' => ['type' => 'string', 'description' => 'The project permalink (e.g., my-project).'],
            ],
          ],
        ],
        [
          'name' => 'get_project_activity',
          'description' => 'Get the recent activity for the project',
          'inputSchema' => [
            'type' => 'object',
            'properties' => [
              'project' => ['type' => 'string', 'description' => 'The project permalink (e.g., my-project).'],
            ],
          ],
        ],
        [
          'name' => 'get_project_users',
          'description' => 'Get all users assigned to the project',
          'inputSchema' => [
            'type' => 'object',
            'properties' => [
              'project' => ['type' => 'string', 'description' => 'The project permalink (e.g., my-project).'],
            ],
          ],
        ],
      ]
    ];
  }

  /**
   * Executes a specific tool call requested by the client.
   *
   * @param string $name
   *   The name of the tool to execute.
   * @param array $args
   *   The arguments passed to the tool.
   *
   * @return array
   *   The result of the tool execution formatted for MCP.
   *
   * @throws \Exception If the tool name is unknown.
   */
  private function callTool(string $project, string $name, array $args): array {
    $content = match ($name) {
      'list_projects' => $this->apiGet("/projects"),
      'get_project' => $this->apiGet("/{$project}/{$args['ticket_id']}"),
      'list_tickets' => $this->apiGet("/{$project}/tickets", ['query' => $args['query'] ?? 'status:open']),
      'get_ticket' => $this->apiGet("/{$project}/tickets/{$args['ticket_id']}/notes"),
      'get_ticket_statuses' => $this->apiGet("/{$project}/tickets/statuses"),
      'get_ticket_priorities' => $this->apiGet("/{$project}/tickets/priorities"),
      'get_ticket_categories' => $this->apiGet("/{$project}/tickets/categories"),
      'get_ticket_types' => $this->apiGet("/{$project}/tickets/types"),
      'create_ticket' => $this->apiPost("/{$project}/tickets", ['ticket' => $args]),
      'update_ticket' => $this->apiPost("/{$project}/tickets/{$args['ticket_id']}/notes", $this->buildTicketNotePayload($args)),
      'get_milestones' => $this->apiGet("/{$project}/milestones"),
      'get_project_activity' => $this->apiGet("/{$project}/activity"),
      'get_project_users' => $this->apiGet("/{$project}/assignments"),
      default => throw new \Exception(sprintf('Unknown tool: %s', $name)),
    };

    return [
      'content' => [
        [
          'type' => 'text',
          'text' => json_encode($content, JSON_PRETTY_PRINT),
        ],
      ],
    ];
  }

  /**
   * Constructs the payload for creating or updating a ticket note/change.
   *
   * Resolves human-readable names (status, priority, etc.) to their internal
   * IDs.
   *
   * @param array $args
   *   The arguments containing ticket update information.
   *
   * @return array
   *   The formatted payload for the Codebase API.
   *
   * @throws \Exception
   */
  private function buildTicketNotePayload(array $args): array {
    $project = $this->project ?? $args['project'] ?? null;
    if (is_null($project)) {
      throw new \Exception('Missing required argument: project. Either set environment variable CODEBASE_PROJECT or pass argument to tool.');
    }

    $ticketNote = [
      'content' => (string) ($args['content'] ?? ''),
    ];

    $changes = [];

    if (!empty($args['status'])) {
      $changes['status_id'] = $this->findPropertyIdByName("/{$project}/tickets/statuses", $args['status']);
    }

    if (!empty($args['priority'])) {
      $changes['priority_id'] = $this->findPropertyIdByName("/{$project}/tickets/priorities", $args['priority']);
    }

    if (!empty($args['category'])) {
      $changes['category_id'] = $this->findPropertyIdByName("/{$project}/tickets/categories", $args['category']);
    }

    if (!empty($args['assignee'])) {
      $changes['assignee_id'] = $this->findProjectUserId($project, $args['assignee']);
    }

    if (!empty($args['summary'])) {
      $changes['summary'] = $args['summary'];
    }

    if ($changes !== []) {
      $ticketNote['changes'] = $changes;
    }

    return ['ticket_note' => $ticketNote];
  }

  /**
   * Helper to find the internal ID of a property (status, priority, etc.) by its name.
   *
   * @param string $path
   *   The API path to fetch the list of properties.
   * @param string $name
   *   The name to search for.
   *
   * @return int
   *   The ID of the found property.
   *
   * @throws \Exception If the property cannot be found.
   */
  private function findPropertyIdByName(string $path, string $name): int {
    $items = $this->apiGet($path);

    foreach ($items as $item) {
      $property = is_array($item) && count($item) === 1 ? reset($item) : $item;
      if (
        is_array($property)
        && isset($property['name'])
        && strcasecmp((string) $property['name'], $name) === 0
        && isset($property['id'])
      ) {
        return (int) $property['id'];
      }
    }

    throw new \Exception(sprintf('Unable to find property "%s" in %s.', $name, $path));
  }

  /**
   * Helper to find a project user's ID by searching their name or username.
   *
   * @param string $project
   *   The project permalink.
   * @param string $search
   *   The name or username to search for.
   *
   * @return int
   *   The internal user ID.
   *
   * @throws \Exception If no user or multiple users are found.
   */
  private function findProjectUserId(string $project, string $search): int {
    $items = $this->apiGet("/{$project}/assignments");
    $searchNormalized = $this->normalizeLookupValue($search);

    $matches = [];

    foreach ($items as $item) {
      $user = is_array($item) && count($item) === 1 ? reset($item) : $item;
      if (!is_array($user) || !isset($user['id'])) {
        continue;
      }

      $candidates = [];

      if (!empty($user['username'])) {
        $candidates[] = (string) $user['username'];
      }

      if (!empty($user['name'])) {
        $candidates[] = (string) $user['name'];
      }

      if (!empty($user['first_name']) || !empty($user['last_name'])) {
        $candidates[] = trim(((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? '')));
      }

      foreach ($candidates as $candidate) {
        if ($this->normalizeLookupValue($candidate) === $searchNormalized) {
          $matches[] = $user;
          break;
        }
      }
    }

    if (count($matches) === 1) {
      return (int) $matches[0]['id'];
    }

    if (count($matches) > 1) {
      throw new \Exception(sprintf('Multiple project users matched "%s". Please use a more specific assignee value.', $search));
    }

    throw new \Exception(sprintf('Unable to find project user "%s".', $search));
  }

  /**
   * Normalizes a string for comparison during lookups.
   *
   * @param string $value
   *   The value to normalize.
   *
   * @return string
   *   The normalized string.
   */
  private function normalizeLookupValue(string $value): string {
    return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value)));
  }

  /**
   * Lists available static resources.
   *
   * @return array
   *   A list of resource definitions.
   */
  private function listResources(): array {
    return [
      'resources' => [
        [
          'uri' => 'docs://tickets/search',
          'name' => 'Ticket Search Guide',
          'description' => 'Information on how to perform ticket search in Codebase',
          'mimeType' => 'markdown'
        ]
      ]
    ];
  }

  /**
   * Reads the content of a specific resource.
   *
   * @param string $uri
   *   The URI of the resource to read.
   *
   * @return array
   *   The resource content.
   *
   * @throws \Exception If the resource URI is not found.
   */
  private function readResource(string $uri): array {
    if ($uri === 'docs://tickets/search') {
      return [
        'contents' => [
          [
            'uri' => $uri,
            'mimeType' => 'markdown',
            'text' => "For detailed information on ticket search, visit: https://support.codebasehq.com/articles/tickets/quick-search\n\nCommon search terms:\n- `status:open` - Show only open tickets\n- `assignee:username` - Search by assignee\n- `priority:high` - Search by priority"
          ]
        ]
      ];
    }
    throw new \Exception(sprintf('Resource not found: %s', $uri));
  }

  /**
   * Performs a GET request to the Codebase API.
   *
   * @param string $path
   *   The relative API path.
   * @param array $params
   *   Optional query parameters.
   *
   * @return array
   *   The decoded JSON response.
   *
   * @throws \Exception On API, curl or network errors.
   */
  private function apiGet(string $path, array $params = []): array {
    $url = $this->baseUrl . $path . '.json';
    if ($params) {
      $url .= '?' . http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "$this->username:$this->apiKey");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);

    $response = curl_exec($ch);

    if ($response === false) {
      $message = curl_error($ch);
      curl_close($ch);
      throw new \Exception(sprintf('cURL error: %s', $message));
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($status >= 400) {
      throw new \Exception(sprintf('Codebase API error (%s): %s', $status, $response));
    }

    curl_close($ch);
    return json_decode($response, true) ?? [];
  }

  /**
   * Performs a POST request to the Codebase API.
   *
   * @param string $path
   *   The relative API path.
   * @param array $data
   *   The data to be sent in the request body.
   *
   * @return array
   *   The decoded JSON response.
   *
   * @throws \Exception On API, curl or network errors.
   */
  private function apiPost(string $path, array $data): array {
    $url = $this->baseUrl . $path . '.json';
    $ch = curl_init($url);
    $payload = json_encode($data);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_USERPWD, "$this->username:$this->apiKey");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Accept: application/json',
      'Content-Type: application/json',
      'Content-Length: ' . strlen($payload),
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
      $message = curl_error($ch);
      curl_close($ch);
      throw new \Exception(sprintf('cURL error: %s', $message));
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($status >= 400) {
      throw new \Exception(sprintf('Codebase API error (%s): %s', $status, $response));
    }

    curl_close($ch);
    return json_decode($response, true) ?? [];
  }

}
