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

  /** Tools that work across projects and need no project argument. */
  private const PROJECTLESS_TOOLS = ['list_projects', 'list_my_tickets'];

  /** Most projects searched in one list_my_tickets call. */
  private const MAX_PROJECTS = 100;

  /** Codebase returns this many tickets per page. */
  private const PAGE_SIZE = 20;

  /** Most pages fetched per project in list_my_tickets. */
  private const MAX_PAGES = 10;

  private const QUERY_HELP = 'Codebase search syntax: status:open, assignee:me, priority:high, type:bug, category:name, milestone:"Release 1". Comma separate values (status:new,accepted), prefix not- to negate (not-status:completed), quote values with spaces. Terms are ANDed. sort:updated_at order:desc sorts.';

  private const INSTRUCTIONS = 'Tools for Codebase HQ. Most tools work on ONE project: pass its permalink as the project argument (list_projects shows the permalinks). To find tickets across ALL of the user\'s projects, for example "what tickets do I have?" or "what is assigned to me?", call list_my_tickets once. Do not call list_tickets for each project. Ticket lists are compact summaries, 20 per page in list_tickets (may_have_more says whether to fetch the next page); use get_ticket for full detail. An empty list means the search matched nothing; projects_skipped and projects_incomplete in list_my_tickets name projects that could not be fully checked.';

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
   * @param float $timeLimit
   *   Seconds allowed for searches that span all projects.
   * @param int $concurrency
   *   Requests in flight at once for searches that span all projects.
   */
  public function __construct(
    private string $username,
    private string $apiKey,
    private ?string $project = null,
    private ?string $baseUrl = null,
    private float $timeLimit = 20.0,
    private int $concurrency = 8,
  ) {
    // If no environment variable for API base URL is set use a default.
    if (!is_null($baseUrl)) {
      $this->baseUrl = rtrim($baseUrl, '/');
    }
    else {
      $this->baseUrl = 'https://api3.codebasehq.com';
    }
  }

  /**
   * Checks that Codebase accepts the configured credentials.
   *
   * @throws \Exception If the credentials are rejected or the API is unreachable.
   */
  public function verifyCredentials(): void {
    $this->apiGet('/projects');
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

      $response = $this->handle($request);
      if ($response !== null) {
        echo json_encode($response) . "\n";
      }
    }
  }

  /**
   * Handles an incoming MCP/JSON-RPC message.
   *
   * Transport independent; used by both the stdio loop and the HTTP endpoint.
   *
   * @param array $request
   *   The decoded JSON request.
   *
   * @return array|null
   *   The JSON-RPC response array, or NULL for notifications (messages without
   *   an id), which must not be answered.
   */
  public function handle(array $request): ?array {
    if (!array_key_exists('id', $request)) {
      return null;
    }

    $method = $request['method'] ?? '';
    $params = is_array($request['params'] ?? null) ? $request['params'] : [];
    $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
    $project = $arguments['project'] ?? $params['project'] ?? $this->project;
    $id = $request['id'];

    try {
      if ($method === 'tools/call' && !in_array($params['name'] ?? '', self::PROJECTLESS_TOOLS, TRUE)) {
        if (is_null($project)) {
          throw new \Exception('Missing required argument: project. Either set environment variable CODEBASE_PROJECT or pass argument to tool.');
        }
        if (!preg_match('/^[A-Za-z0-9_-]+$/', (string) $project)) {
          throw new \Exception('Invalid project permalink.');
        }
      }

      $result = match ($method) {
        'initialize' => $this->initialize((string) ($params['protocolVersion'] ?? '')),
        'ping' => (object) [],
        'tools/list' => $this->listTools(),
        'tools/call' => $this->callTool((string) ($project ?? ''), $params['name'] ?? '', $arguments),
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
      if ($method === 'tools/call') {
        // Never log credentials; the message only holds API status and path.
        error_log(sprintf(
          'Tool call failed: tool=%s project=%s error=%s',
          $params['name'] ?? '-',
          $project ?? '-',
          substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 300)
        ));
      }
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
   * @param string $requestedVersion
   *   The protocol version requested by the client.
   *
   * @return array
   *   Initial server metadata.
   */
  private function initialize(string $requestedVersion): array {
    $supported = ['2025-06-18', '2025-03-26', '2024-11-05'];
    return [
      'protocolVersion' => in_array($requestedVersion, $supported, true) ? $requestedVersion : $supported[0],
      'capabilities' => [
        'tools' => (object)[],
        'resources' => (object)[],
      ],
      'serverInfo' => [
        'name' => 'codebase-hq-mcp-server',
        'version' => '1.0.1',
      ],
      'instructions' => self::INSTRUCTIONS,
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
          'description' => 'List the projects the user can access, with the permalinks that other tools take as their project argument. To find tickets across projects, use list_my_tickets instead of searching each project.',
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
          'description' => 'List tickets in ONE project as compact summaries, 20 per page (use get_ticket for full detail). may_have_more is true when a full page was returned: request the next page. An empty list means nothing matched. To search all of the user\'s projects at once, use list_my_tickets. ' . self::QUERY_HELP,
          'inputSchema' => [
            'type' => 'object',
            'properties' => [
              'project' => ['type' => 'string', 'description' => 'The project permalink (e.g., my-project).'],
              'query' => ['type' => 'string', 'description' => 'Search query (default: status:open), e.g. assignee:me priority:high.'],
              'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page number, starting at 1 (20 tickets per page).'],
            ],
          ],
        ],
        [
          'name' => 'list_my_tickets',
          'description' => 'Find tickets across ALL of the user\'s active projects in one call. Defaults to the user\'s open tickets (assignee:me status:open). Use this for questions such as "what tickets do I have?" instead of calling list_tickets per project. Projects are searched in parallel and extra pages are fetched automatically. projects_skipped lists projects that could not be checked and why; projects_incomplete lists projects where only some tickets were returned. Returns compact summaries; use get_ticket for full detail. ' . self::QUERY_HELP,
          'inputSchema' => [
            'type' => 'object',
            'properties' => [
              'query' => ['type' => 'string', 'description' => 'Search query applied to every project (default: assignee:me status:open).'],
              'projects' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional project permalinks to search instead of all active projects.'],
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
              'type' => ['type' => 'string', 'enum' => ['bug', 'enhancement', 'task'], 'description' => 'Ticket type'],
              'status' => ['type' => 'string', 'description' => 'Status name (e.g., New, Open). Defaults to the project\'s first open status.'],
              'priority' => ['type' => 'string', 'description' => 'Priority name (e.g., Low, Normal, High). Defaults to the project\'s default priority.'],
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
    if (str_contains($name, 'ticket') && isset($args['ticket_id'])) {
      if (!is_scalar($args['ticket_id']) || !preg_match('/^\d+$/', (string) $args['ticket_id'])) {
        throw new \Exception('Invalid ticket_id.');
      }
      $args['ticket_id'] = (int) $args['ticket_id'];
    }

    $content = match ($name) {
      'list_projects' => $this->apiGet("/projects"),
      'get_project' => $this->apiGet("/{$project}"),
      'list_tickets' => $this->listTicketsPage($project, $args),
      'list_my_tickets' => $this->listMyTickets($args),
      'get_ticket' => $this->apiGet("/{$project}/tickets/{$args['ticket_id']}"),
      'get_ticket_notes' => $this->apiGet("/{$project}/tickets/{$args['ticket_id']}/notes"),
      'get_ticket_statuses' => $this->apiGet("/{$project}/tickets/statuses"),
      'get_ticket_priorities' => $this->apiGet("/{$project}/tickets/priorities"),
      'get_ticket_categories' => $this->apiGet("/{$project}/tickets/categories"),
      'get_ticket_types' => $this->apiGet("/{$project}/tickets/types"),
      'create_ticket' => $this->apiPost("/{$project}/tickets", $this->buildTicketCreatePayload($project, $args)),
      'update_ticket' => $this->apiPost("/{$project}/tickets/{$args['ticket_id']}/notes", $this->buildTicketNotePayload($project, $args)),
      'get_milestones' => $this->apiGet("/{$project}/milestones"),
      'get_project_activity' => $this->apiGet("/{$project}/activity"),
      'get_project_users' => $this->apiGet("/{$project}/assignments"),
      default => throw new \Exception(sprintf('Unknown tool: %s', $name)),
    };

    return [
      'content' => [
        [
          'type' => 'text',
          // Compact and unescaped: pretty printing and \\uXXXX escapes cost tokens.
          'text' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ],
      ],
    ];
  }

  /**
   * One page of a ticket search in a single project, as compact summaries.
   *
   * @param array $args
   *   Optional query and page.
   *
   * @return array
   *   The page, how many tickets it holds and whether more may follow.
   *
   * @throws \Exception If the arguments are invalid or the search fails.
   */
  private function listTicketsPage(string $project, array $args): array {
    $page = $args['page'] ?? 1;
    if (!is_scalar($page) || !preg_match('/^[1-9]\d{0,3}$/', (string) $page)) {
      throw new \Exception('Invalid page.');
    }
    $page = (int) $page;
    $query = isset($args['query']) && is_string($args['query']) && trim($args['query']) !== '' ? trim($args['query']) : 'status:open';

    $tickets = TicketFormatter::compactList($this->listTickets($project, $query, $page));
    return [
      'page' => $page,
      'count' => count($tickets),
      // A full page may be followed by another; a short one is the last.
      'may_have_more' => count($tickets) >= self::PAGE_SIZE,
      'tickets' => $tickets,
    ];
  }

  /**
   * Searches tickets, treating "no matches" as an empty list.
   *
   * Codebase answers a search without results with 404 and an empty list.
   * On the first page that is only an empty result if the project itself
   * exists, so check it before swallowing the error. Past the last page a
   * 404 simply means there is nothing more.
   *
   * @return array
   *   The matching tickets.
   *
   * @throws \Exception If the search or the project lookup fails.
   */
  private function listTickets(string $project, string $query, int $page = 1): array {
    $params = ['query' => $query] + ($page > 1 ? ['page' => $page] : []);
    try {
      return $this->apiGet("/{$project}/tickets", $params);
    }
    catch (CodebaseApiException $e) {
      if ($e->status !== 404) {
        throw $e;
      }
    }

    if ($page > 1) {
      return [];
    }
    // Throws the explicit "not found" error if the project does not exist.
    $this->apiGet("/{$project}");
    return [];
  }

  /**
   * Searches tickets across all (or the given) projects in parallel.
   *
   * Projects are searched in rounds, one page each, so a project with more
   * than a page of matches is followed up without holding back the others.
   * All rounds share one time limit.
   *
   * @param array $args
   *   Optional query and projects.
   *
   * @return array
   *   The tickets found, how many projects were searched, and the projects
   *   that could not be checked, or only partly, with the reason.
   *
   * @throws \Exception If the arguments are invalid or the project list fails.
   */
  private function listMyTickets(array $args): array {
    $query = isset($args['query']) && is_string($args['query']) && trim($args['query']) !== ''
      ? trim($args['query'])
      : 'assignee:me status:open';
    // Only queries for open tickets can be narrowed by the open ticket count.
    $openOnly = (bool) preg_match('/(^|\s)status:open(\s|$)/', $query);

    $skipped = [];
    $explicit = isset($args['projects']);
    $withoutOpen = 0;
    if ($explicit) {
      if (!is_array($args['projects'])) {
        throw new \Exception('projects must be a list of project permalinks.');
      }
      foreach ($args['projects'] as $permalink) {
        if (!is_string($permalink) || !preg_match('/^[A-Za-z0-9_-]+$/', $permalink)) {
          throw new \Exception('Invalid project permalink in projects.');
        }
      }
      $permalinks = array_values(array_unique($args['projects']));
    }
    else {
      $permalinks = [];
      foreach ($this->activeProjects($this->apiGet('/projects')) as $project) {
        if ($openOnly && $project['open_tickets'] === 0) {
          $withoutOpen++;
        }
        else {
          $permalinks[] = $project['permalink'];
        }
      }
    }

    if (count($permalinks) > self::MAX_PROJECTS) {
      foreach (array_slice($permalinks, self::MAX_PROJECTS) as $permalink) {
        $skipped[] = ['project' => $permalink, 'reason' => 'Not checked: more than ' . self::MAX_PROJECTS . ' projects.'];
      }
      $permalinks = array_slice($permalinks, 0, self::MAX_PROJECTS);
    }

    $deadline = microtime(TRUE) + $this->timeLimit;
    $tickets = [];
    $incomplete = [];
    $checked = 0;
    $found = array_fill_keys($permalinks, 0);
    // Project => the page to fetch next.
    $round = array_fill_keys($permalinks, 1);

    while ($round) {
      $remaining = $deadline - microtime(TRUE);
      $paths = [];
      foreach ($round as $permalink => $page) {
        $paths[$permalink] = "/{$permalink}/tickets.json?" . http_build_query(['query' => $query] + ($page > 1 ? ['page' => $page] : []));
      }
      $fetcher = new ParallelFetcher($this->baseUrl, $this->username, $this->apiKey, $this->concurrency, max(0.0, $remaining));
      $next = [];

      foreach ($fetcher->get($paths) as $permalink => $result) {
        $page = $round[$permalink];
        $failure = $result['error']
          ?? ($result['status'] >= 400 && $result['status'] !== 404
            ? $this->apiError($result['status'], "/{$permalink}/tickets", (string) $result['body'])->getMessage()
            : NULL);

        if ($failure !== NULL) {
          // Nothing from this project, or only the pages before this one.
          if ($page === 1) {
            $skipped[] = ['project' => $permalink, 'reason' => $failure];
          }
          else {
            $incomplete[] = ['project' => $permalink, 'tickets_returned' => $found[$permalink], 'reason' => $failure];
          }
          continue;
        }

        if ($page === 1) {
          $checked++;
        }

        if ($result['status'] === 404) {
          // Codebase's answer to a search without matches (or past the last
          // page). For projects we were told to search, confirm they exist.
          if ($explicit && $page === 1) {
            try {
              $this->apiGet("/{$permalink}");
            }
            catch (\Exception $e) {
              $checked--;
              $skipped[] = ['project' => $permalink, 'reason' => $e->getMessage()];
            }
          }
          continue;
        }

        $list = json_decode((string) $result['body'], TRUE);
        if (!is_array($list) || !array_is_list($list)) {
          if ($page === 1) {
            $checked--;
            $skipped[] = ['project' => $permalink, 'reason' => 'Unexpected response from Codebase.'];
          }
          else {
            $incomplete[] = ['project' => $permalink, 'tickets_returned' => $found[$permalink], 'reason' => 'Unexpected response from Codebase.'];
          }
          continue;
        }

        foreach (TicketFormatter::compactList($list, $permalink) as $ticket) {
          $tickets[] = $ticket;
        }
        $found[$permalink] += count($list);

        if (count($list) >= self::PAGE_SIZE) {
          if ($page >= self::MAX_PAGES) {
            $incomplete[] = ['project' => $permalink, 'tickets_returned' => $found[$permalink], 'reason' => 'More matches exist; stopped after ' . self::MAX_PAGES . ' pages. Narrow the query or use list_tickets with page.'];
          }
          else {
            $next[$permalink] = $page + 1;
          }
        }
      }
      $round = $next;

      // Out of time with pages still to fetch: say so for each project.
      if ($round && $deadline - microtime(TRUE) <= 0.05) {
        foreach ($round as $permalink => $page) {
          $incomplete[] = ['project' => $permalink, 'tickets_returned' => $found[$permalink], 'reason' => 'Time limit reached; more pages not fetched.'];
        }
        break;
      }
    }

    return [
      'query' => $query,
      'projects_checked' => $checked,
      'projects_without_open_tickets' => $withoutOpen,
      'ticket_count' => count($tickets),
      'tickets' => $tickets,
      'projects_skipped' => $skipped,
      'projects_incomplete' => $incomplete,
    ];
  }

  /**
   * Extracts the active projects from the project list.
   *
   * @param array $projects
   *   The decoded response of the projects endpoint.
   *
   * @return array<int, array{permalink: string, open_tickets: ?int}>
   *   Archived projects are left out. open_tickets is NULL if not reported.
   */
  private function activeProjects(array $projects): array {
    $active = [];
    $seen = [];
    foreach ($projects as $item) {
      $project = is_array($item) && isset($item['project']) && is_array($item['project']) ? $item['project'] : $item;
      if (!is_array($project) || !isset($project['permalink']) || !preg_match('/^[A-Za-z0-9_-]+$/', (string) $project['permalink'])) {
        continue;
      }
      $status = strtolower((string) ($project['status'] ?? ''));
      if (!empty($project['archived']) || in_array($status, ['archived', 'inactive', 'closed'], TRUE)) {
        continue;
      }
      $permalink = (string) $project['permalink'];
      if (isset($seen[$permalink])) {
        continue;
      }
      $seen[$permalink] = TRUE;
      $active[] = [
        'permalink' => $permalink,
        'open_tickets' => isset($project['open_tickets']) && is_numeric($project['open_tickets']) ? (int) $project['open_tickets'] : NULL,
      ];
    }
    return $active;
  }

  /**
   * Constructs the payload for creating a ticket.
   *
   * Codebase wants ids, not names, and requires a status and a priority, so
   * names are resolved and missing ones fall back to the project's defaults.
   *
   * @param string $project
   *   The project permalink.
   * @param array $args
   *   The tool arguments.
   *
   * @return array
   *   The payload for the Codebase API.
   *
   * @throws \Exception If a value cannot be resolved.
   */
  private function buildTicketCreatePayload(string $project, array $args): array {
    $summary = trim((string) ($args['summary'] ?? ''));
    if ($summary === '') {
      throw new \Exception('summary is required.');
    }
    $ticket = ['summary' => $summary];

    if (!empty($args['description'])) {
      $ticket['description'] = (string) $args['description'];
    }

    if (!empty($args['type'])) {
      $type = strtolower((string) $args['type']);
      if (!in_array($type, ['bug', 'enhancement', 'task'], TRUE)) {
        throw new \Exception('type must be one of: bug, enhancement, task.');
      }
      $ticket['ticket_type'] = $type;
    }

    $ticket['status_id'] = !empty($args['status'])
      ? $this->findPropertyIdByName("/{$project}/tickets/statuses", (string) $args['status'])
      : $this->defaultStatusId($project);
    $ticket['priority_id'] = !empty($args['priority'])
      ? $this->findPropertyIdByName("/{$project}/tickets/priorities", (string) $args['priority'])
      : $this->defaultPriorityId($project);

    if (!empty($args['category'])) {
      $ticket['category_id'] = $this->findPropertyIdByName("/{$project}/tickets/categories", (string) $args['category']);
    }
    if (!empty($args['assignee'])) {
      $ticket['assignee_id'] = $this->findProjectUserId($project, (string) $args['assignee']);
    }

    return ['ticket' => $ticket];
  }

  /**
   * The id of the project's first status that is not a closing one.
   *
   * @throws \Exception If the project has no usable status.
   */
  private function defaultStatusId(string $project): int {
    $statuses = array_filter(
      array_map(fn($item) => is_array($item) && count($item) === 1 ? reset($item) : $item, $this->apiGet("/{$project}/tickets/statuses")),
      fn($status) => is_array($status) && isset($status['id']) && empty($status['treat_as_closed'])
    );
    if (!$statuses) {
      throw new \Exception('Unable to determine a default status; pass status.');
    }
    usort($statuses, fn($a, $b) => ($a['order'] ?? PHP_INT_MAX) <=> ($b['order'] ?? PHP_INT_MAX));
    return (int) $statuses[0]['id'];
  }

  /**
   * The id of the project's default priority, else its first one.
   *
   * @throws \Exception If the project has no priorities.
   */
  private function defaultPriorityId(string $project): int {
    $priorities = array_values(array_filter(
      array_map(fn($item) => is_array($item) && count($item) === 1 ? reset($item) : $item, $this->apiGet("/{$project}/tickets/priorities")),
      fn($priority) => is_array($priority) && isset($priority['id'])
    ));
    if (!$priorities) {
      throw new \Exception('Unable to determine a default priority; pass priority.');
    }
    foreach ($priorities as $priority) {
      if (!empty($priority['default'])) {
        return (int) $priority['id'];
      }
    }
    usort($priorities, fn($a, $b) => ($a['position'] ?? PHP_INT_MAX) <=> ($b['position'] ?? PHP_INT_MAX));
    return (int) $priorities[0]['id'];
  }

  /**
   * Constructs the payload for creating or updating a ticket note/change.
   *
   * Resolves human-readable names (status, priority, etc.) to their internal
   * IDs.
   *
   * @param string $project
   *   The project permalink.
   * @param array $args
   *   The arguments containing ticket update information.
   *
   * @return array
   *   The formatted payload for the Codebase API.
   *
   * @throws \Exception
   */
  private function buildTicketNotePayload(string $project, array $args): array {
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
   * Builds the exception for a failed Codebase API call.
   *
   * A bare 404 is ambiguous (missing project, no access, or an empty search),
   * so say what was not found. Callers that know an empty result is possible
   * handle the 404 themselves.
   */
  private function apiError(int $status, string $path, string $response): CodebaseApiException {
    if ($status === 404) {
      return new CodebaseApiException(sprintf(
        'Codebase API error (404): %s was not found. The project may not exist, may have no ticket tracker enabled, or your Codebase account may not have access to it.',
        $path
      ), $status);
    }
    return new CodebaseApiException(sprintf('Codebase API error (%s): %s', $status, $response), $status);
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
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
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
      throw $this->apiError($status, $path, (string) $response);
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
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
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
      throw $this->apiError($status, $path, (string) $response);
    }

    curl_close($ch);
    return json_decode($response, true) ?? [];
  }

}
