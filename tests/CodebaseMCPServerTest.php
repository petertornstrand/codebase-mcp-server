<?php

namespace petertornstrand\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use petertornstrand\CodebaseMCPServer;

class CodebaseMCPServerTest extends TestCase {

  private static FakeCodebase $api;

  public static function setUpBeforeClass(): void {
    self::$api = new FakeCodebase();
  }

  protected function setUp(): void {
    self::$api->reset();
  }

  private function server(string $user = 'acme/peter', string $key = 'good-key', ?string $project = NULL): CodebaseMCPServer {
    return new CodebaseMCPServer($user, $key, $project, self::$api->url);
  }

  private function rpc(CodebaseMCPServer $server, string $method, array $params = [], int|string $id = 1): array {
    return $server->handle(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
  }

  private function call(CodebaseMCPServer $server, string $tool, array $args = []): array {
    return $this->rpc($server, 'tools/call', ['name' => $tool, 'arguments' => $args]);
  }

  /** Decodes the JSON text returned by a successful tool call. */
  private function payload(array $response): mixed {
    $this->assertArrayNotHasKey('error', $response, json_encode($response));
    $this->assertSame('text', $response['result']['content'][0]['type']);
    return json_decode($response['result']['content'][0]['text'], TRUE);
  }

  private function error(array $response): string {
    $this->assertArrayHasKey('error', $response);
    $this->assertSame(-32603, $response['error']['code']);
    return $response['error']['message'];
  }

  // Protocol.

  public function testInitializeNegotiatesProtocolVersion(): void {
    $server = $this->server();
    foreach (['2025-06-18', '2025-03-26', '2024-11-05'] as $version) {
      $this->assertSame($version, $this->rpc($server, 'initialize', ['protocolVersion' => $version])['result']['protocolVersion']);
    }
    // Unknown or missing versions get the newest one we support.
    $this->assertSame('2025-06-18', $this->rpc($server, 'initialize', ['protocolVersion' => '1999-01-01'])['result']['protocolVersion']);
    $this->assertSame('2025-06-18', $this->rpc($server, 'initialize')['result']['protocolVersion']);

    $result = $this->rpc($server, 'initialize')['result'];
    $this->assertSame('codebase-hq-mcp-server', $result['serverInfo']['name']);
    $this->assertArrayHasKey('tools', $result['capabilities']);
  }

  public function testInitializeAndListNeedNoProject(): void {
    $server = $this->server();
    $this->assertArrayHasKey('result', $this->rpc($server, 'initialize'));
    $this->assertArrayHasKey('result', $this->rpc($server, 'tools/list'));
    $this->assertSame([], self::$api->requests());
  }

  public function testPingReturnsEmptyObject(): void {
    $result = $this->rpc($this->server(), 'ping')['result'];
    $this->assertInstanceOf(\stdClass::class, $result);
  }

  public function testNotificationsGetNoResponse(): void {
    $server = $this->server();
    $this->assertNull($server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
    // Even an unknown method must stay silent without an id.
    $this->assertNull($server->handle(['jsonrpc' => '2.0', 'method' => 'does/not/exist']));
  }

  public function testResponseEchoesIdIncludingStringIds(): void {
    $this->assertSame('abc', $this->rpc($this->server(), 'ping', [], 'abc')['id']);
    $this->assertSame(0, $this->server()->handle(['jsonrpc' => '2.0', 'id' => 0, 'method' => 'ping'])['id']);
  }

  public function testUnknownMethodIsAnError(): void {
    $this->assertSame('Method not found: nope', $this->error($this->rpc($this->server(), 'nope')));
  }

  public function testToolsListDescribesEveryTool(): void {
    $tools = $this->rpc($this->server(), 'tools/list')['result']['tools'];
    $names = array_column($tools, 'name');
    $this->assertCount(15, $names);
    $this->assertSame($names, array_values(array_unique($names)));
    foreach ($tools as $tool) {
      $this->assertNotSame('', $tool['description'], $tool['name']);
      $this->assertSame('object', $tool['inputSchema']['type'], $tool['name']);
    }
  }

  public function testEveryListedToolIsDispatchable(): void {
    $server = $this->server(project: 'acme');
    foreach ($this->rpc($server, 'tools/list')['result']['tools'] as $tool) {
      $args = ['ticket_id' => 12, 'summary' => 'x'];
      $response = $this->call($server, $tool['name'], $args);
      $message = $response['error']['message'] ?? '';
      $this->assertStringNotContainsString('Unknown tool', $message, $tool['name']);
    }
  }

  public function testResources(): void {
    $server = $this->server();
    $list = $this->rpc($server, 'resources/list')['result']['resources'];
    $this->assertSame('docs://tickets/search', $list[0]['uri']);

    $read = $this->rpc($server, 'resources/read', ['uri' => 'docs://tickets/search'])['result']['contents'][0];
    $this->assertStringContainsString('status:open', $read['text']);

    $this->assertStringContainsString('Resource not found', $this->error($this->rpc($server, 'resources/read', ['uri' => 'docs://nope'])));
  }

  // Project handling.

  public function testToolsNeedAProject(): void {
    $message = $this->error($this->call($this->server(), 'get_project'));
    $this->assertStringContainsString('Missing required argument: project', $message);
    $this->assertSame([], self::$api->requests());
  }

  public function testListProjectsNeedsNoProject(): void {
    $projects = $this->payload($this->call($this->server(), 'list_projects'));
    $this->assertSame('acme', $projects[0]['project']['permalink']);
  }

  public function testProjectArgumentBeatsDefault(): void {
    $server = $this->server(project: 'default');
    $this->call($server, 'get_project', ['project' => 'acme']);
    $this->call($server, 'get_project');
    $this->assertSame(['/acme.json', '/default.json'], array_column(self::$api->requests(), 'path'));
  }

  #[DataProvider('badProjects')]
  public function testRejectsUnsafeProjectPermalinks(string $project): void {
    $this->assertSame('Invalid project permalink.', $this->error($this->call($this->server(), 'get_project', ['project' => $project])));
    $this->assertSame([], self::$api->requests());
  }

  public static function badProjects(): array {
    return [['../admin'], ['a/b'], ['a b'], ['a?x=1'], ['a%2fb'], [''], ["a\nb"]];
  }

  #[DataProvider('badTicketIds')]
  public function testRejectsInvalidTicketIds(mixed $id): void {
    $this->assertSame('Invalid ticket_id.', $this->error($this->call($this->server(), 'get_ticket', ['project' => 'acme', 'ticket_id' => $id])));
    $this->assertSame([], self::$api->requests());
  }

  public static function badTicketIds(): array {
    return [['1/../../x'], ['abc'], [-1], ['1.5'], ['12 '], [''], [[1]]];
  }

  public function testNumericStringTicketIdIsAccepted(): void {
    $this->call($this->server(), 'get_ticket', ['project' => 'acme', 'ticket_id' => '012']);
    $this->assertSame('/acme/tickets/12.json', self::$api->requests()[0]['path']);
  }

  // Read tools.

  #[DataProvider('readTools')]
  public function testReadToolsHitTheRightEndpoint(string $tool, array $args, string $path): void {
    $response = $this->call($this->server(), $tool, ['project' => 'acme'] + $args);
    $this->payload($response);
    $request = self::$api->requests()[0];
    $this->assertSame('GET', $request['method']);
    $this->assertSame($path, $request['path']);
    $this->assertSame('application/json', $request['accept']);
  }

  public static function readTools(): array {
    return [
      'get_project' => ['get_project', [], '/acme.json'],
      'get_ticket' => ['get_ticket', ['ticket_id' => 7], '/acme/tickets/7.json'],
      'get_ticket_notes' => ['get_ticket_notes', ['ticket_id' => 7], '/acme/tickets/7/notes.json'],
      'get_ticket_statuses' => ['get_ticket_statuses', [], '/acme/tickets/statuses.json'],
      'get_ticket_priorities' => ['get_ticket_priorities', [], '/acme/tickets/priorities.json'],
      'get_ticket_categories' => ['get_ticket_categories', [], '/acme/tickets/categories.json'],
      'get_ticket_types' => ['get_ticket_types', [], '/acme/tickets/types.json'],
      'get_milestones' => ['get_milestones', [], '/acme/milestones.json'],
      'get_project_activity' => ['get_project_activity', [], '/acme/activity.json'],
      'get_project_users' => ['get_project_users', [], '/acme/assignments.json'],
    ];
  }

  public function testListTicketsDefaultsToOpenAndPassesQuery(): void {
    $server = $this->server(project: 'acme');
    $this->call($server, 'list_tickets');
    $this->call($server, 'list_tickets', ['query' => 'assignee:peter priority:high']);
    $requests = self::$api->requests();
    $this->assertSame('status:open', $requests[0]['query']['query']);
    $this->assertSame('assignee:peter priority:high', $requests[1]['query']['query']);
  }

  public function testRequestsUseBasicAuthWithTheCallersCredentials(): void {
    $this->call($this->server(), 'list_projects');
    $request = self::$api->requests()[0];
    $this->assertSame('acme/peter', $request['user']);
    $this->assertSame('good-key', $request['pass']);
  }

  // Write tools.

  public function testCreateTicketPostsArgumentsWithoutTheProject(): void {
    $payload = $this->payload($this->call($this->server(), 'create_ticket', [
      'project' => 'acme', 'summary' => 'Broken login', 'description' => 'Details',
    ]));
    $this->assertSame(99, $payload['ticket']['ticket_id']);

    $request = self::$api->requests()[0];
    $this->assertSame('POST', $request['method']);
    $this->assertSame('/acme/tickets.json', $request['path']);
    $this->assertSame(['ticket' => ['summary' => 'Broken login', 'description' => 'Details']], $request['body']);
  }

  public function testUpdateTicketWithOnlyANoteSendsNoChanges(): void {
    $this->call($this->server(), 'update_ticket', ['project' => 'acme', 'ticket_id' => 12, 'content' => 'Looking into it']);
    $request = self::$api->requests()[0];
    $this->assertSame('/acme/tickets/12/notes.json', $request['path']);
    $this->assertSame(['ticket_note' => ['content' => 'Looking into it']], $request['body']);
  }

  public function testUpdateTicketResolvesNamesToIds(): void {
    $this->payload($this->call($this->server(), 'update_ticket', [
      'project' => 'acme', 'ticket_id' => 12, 'content' => 'Done',
      'status' => 'closed', 'priority' => 'HIGH', 'category' => 'Bug', 'assignee' => '  peter   TORNSTRAND ',
      'summary' => 'New title',
    ]));
    $body = array_values(array_filter(self::$api->requests(), fn($r) => $r['method'] === 'POST'))[0]['body'];
    $this->assertSame([
      'status_id' => 11,
      'priority_id' => 20,
      'category_id' => 30,
      'assignee_id' => 5,
      'summary' => 'New title',
    ], $body['ticket_note']['changes']);
  }

  public function testAssigneeCanBeMatchedByUsername(): void {
    $this->call($this->server(), 'update_ticket', ['project' => 'acme', 'ticket_id' => 12, 'assignee' => 'Peter']);
    $post = array_values(array_filter(self::$api->requests(), fn($r) => $r['method'] === 'POST'))[0];
    $this->assertSame(5, $post['body']['ticket_note']['changes']['assignee_id']);
  }

  public function testAmbiguousAssigneeIsRejectedBeforeAnythingIsPosted(): void {
    $message = $this->error($this->call($this->server(), 'update_ticket', ['project' => 'acme', 'ticket_id' => 12, 'assignee' => 'Anna Andersson']));
    $this->assertStringContainsString('Multiple project users matched', $message);
    $this->assertEmpty(array_filter(self::$api->requests(), fn($r) => $r['method'] === 'POST'));
  }

  public function testUnknownLookupValuesAreRejectedBeforeAnythingIsPosted(): void {
    $this->assertStringContainsString('Unable to find project user "nobody"',
      $this->error($this->call($this->server(), 'update_ticket', ['project' => 'acme', 'ticket_id' => 12, 'assignee' => 'nobody'])));
    $this->assertStringContainsString('Unable to find property "Reopened"',
      $this->error($this->call($this->server(), 'update_ticket', ['project' => 'acme', 'ticket_id' => 12, 'status' => 'Reopened'])));
    $this->assertEmpty(array_filter(self::$api->requests(), fn($r) => $r['method'] === 'POST'));
  }

  // Cross-project search.

  /** Requests the fake API got for ticket searches, keyed by project. */
  private function searches(): array {
    $found = [];
    foreach (self::$api->requests() as $request) {
      if (preg_match('#^/([^/]+)/tickets\.json$#', $request['path'], $m) && $request['method'] === 'GET') {
        $found[$m[1]] = $request;
      }
    }
    return $found;
  }

  public function testListMyTicketsSearchesEveryActiveProjectWithoutAProject(): void {
    $result = $this->payload($this->call($this->server(), 'list_my_tickets'));

    $this->assertSame('assignee:me status:open', $result['query']);
    $byProject = [];
    foreach ($result['tickets'] as $ticket) {
      $byProject[$ticket['project']][] = $ticket['id'];
    }
    $this->assertSame([12, 13], $byProject['acme']);
    $this->assertSame([7], $byProject['beta']);

    // Archived, malformed and (for an open-only query) ticket-free projects
    // are never searched.
    $searched = array_keys($this->searches());
    sort($searched);
    $this->assertSame(['acme', 'beta', 'broken', 'endless', 'many', 'quiet', 'weird'], $searched);
    $this->assertSame(1, $result['projects_without_open_tickets']);
    foreach ($this->searches() as $request) {
      $this->assertSame('assignee:me status:open', $request['query']['query']);
      $this->assertSame(['acme/peter', 'good-key'], [$request['user'], $request['pass']]);
    }
  }

  public function testListMyTicketsReportsWhatItCouldNotCheck(): void {
    $result = $this->payload($this->call($this->server(), 'list_my_tickets'));

    $skipped = array_column($result['projects_skipped'], 'reason', 'project');
    $this->assertEqualsCanonicalizing(['broken', 'weird'], array_keys($skipped));
    $this->assertStringContainsString('(500)', $skipped['broken']);
    $this->assertSame('Unexpected response from Codebase.', $skipped['weird']);

    // A search without matches is not a failure, and is not skipped.
    $this->assertArrayNotHasKey('quiet', $skipped);
    $this->assertSame(5, $result['projects_checked'], 'acme, beta, quiet, many and endless');
    // The project list already proved it exists: no extra lookup.
    $this->assertNotContains('/quiet.json', array_column(self::$api->requests(), 'path'));
  }

  public function testListMyTicketsOutputIsCompact(): void {
    $response = $this->call($this->server(), 'list_my_tickets', ['projects' => ['acme']]);
    $text = $response['result']['content'][0]['text'];

    $first = json_decode($text, TRUE)['tickets'][0];
    $this->assertSame([
      'project' => 'acme', 'id' => 12, 'summary' => 'Fix login', 'type' => 'bug', 'status' => 'In Progress',
      'priority' => 'High', 'assignee' => 'peter', 'milestone' => 'Rel 1', 'updated_at' => '2026-10-01T10:00:00Z',
    ], $first);
    $this->assertStringNotContainsString('description', $text);
    $this->assertStringNotContainsString("\n", $text, 'not pretty printed');
    $this->assertStringContainsString('Åäö på svenska', $text);
    $this->assertStringNotContainsString('\\u00', $text, 'unicode is not escaped');
  }

  public function testListMyTicketsPassesACustomQuery(): void {
    $this->call($this->server(), 'list_my_tickets', ['query' => '  priority:high  ']);
    $this->assertSame('priority:high', $this->searches()['acme']['query']['query']);

    self::$api->reset();
    $this->call($this->server(), 'list_my_tickets', ['query' => '   ']);
    $this->assertSame('assignee:me status:open', $this->searches()['acme']['query']['query'], 'blank falls back to the default');
  }

  #[DataProvider('queriesThatCannotSkipEmptyProjects')]
  public function testProjectsWithoutOpenTicketsAreOnlySkippedForOpenOnlyQueries(string $query): void {
    $result = $this->payload($this->call($this->server(), 'list_my_tickets', ['query' => $query]));
    $this->assertArrayHasKey('idle', $this->searches(), "$query must search the project with no open tickets");
    $this->assertSame(0, $result['projects_without_open_tickets']);
  }

  public static function queriesThatCannotSkipEmptyProjects(): array {
    return [
      'closed tickets' => ['status:closed assignee:me'],
      'any status' => ['assignee:me'],
      'open or new' => ['status:open,new'],
      'not open' => ['not-status:open'],
    ];
  }

  public function testExplicitProjectsAreNeverSkippedForHavingNoOpenTickets(): void {
    $this->call($this->server(), 'list_my_tickets', ['projects' => ['idle']]);
    $this->assertArrayHasKey('idle', $this->searches());
  }

  public function testListMyTicketsFollowsAdditionalPages(): void {
    $result = $this->payload($this->call($this->server(), 'list_my_tickets', ['projects' => ['many']]));
    $this->assertSame(45, $result['ticket_count']);
    $ids = array_column($result['tickets'], 'id');
    $this->assertSame(range(1000, 1044), $ids, 'every ticket, once, in order');
    $this->assertSame([], $result['projects_incomplete']);

    $pages = array_map(fn($r) => (int) ($r['query']['page'] ?? 1), array_values(array_filter(self::$api->requests(), fn($r) => $r['path'] === '/many/tickets.json')));
    sort($pages);
    $this->assertSame([1, 2, 3], $pages, 'stops after the first short page');
  }

  public function testListMyTicketsStopsAtThePageLimitAndSaysSo(): void {
    $result = $this->payload($this->call($this->server(), 'list_my_tickets', ['projects' => ['endless', 'acme']]));
    $this->assertSame([['project' => 'endless', 'tickets_returned' => 200,
      'reason' => 'More matches exist; stopped after 10 pages. Narrow the query or use list_tickets with page.']], $result['projects_incomplete']);
    $this->assertSame(202, $result['ticket_count'], '10 full pages plus the other project');
    $this->assertCount(10, array_filter(self::$api->requests(), fn($r) => $r['path'] === '/endless/tickets.json'));
  }

  public function testOtherProjectsAreNotHeldBackByALongOne(): void {
    $result = $this->payload($this->call($this->server(), 'list_my_tickets', ['projects' => ['endless', 'beta']]));
    $this->assertContains(7, array_column($result['tickets'], 'id'));
  }

  public function testListMyTicketsCanBeLimitedToGivenProjects(): void {
    $result = $this->payload($this->call($this->server(), 'list_my_tickets', ['projects' => ['beta', 'beta', 'acme']]));
    $this->assertSame(3, $result['ticket_count']);
    $this->assertEqualsCanonicalizing(['acme', 'beta'], array_keys($this->searches()));
    $this->assertNotContains('/projects.json', array_column(self::$api->requests(), 'path'), 'no project list needed');
    $this->assertCount(2, $this->searches(), 'duplicates are searched once');
  }

  public function testExplicitProjectsAreCheckedForExistence(): void {
    $result = $this->payload($this->call($this->server(), 'list_my_tickets', ['projects' => ['quiet', 'missing']]));
    $this->assertSame(0, $result['ticket_count']);

    // "quiet" exists and simply has no matches; "missing" does not exist.
    $this->assertSame(['missing'], array_column($result['projects_skipped'], 'project'));
    $this->assertStringContainsString('was not found', $result['projects_skipped'][0]['reason']);
    $this->assertContains('/quiet.json', array_column(self::$api->requests(), 'path'));
  }

  public function testListMyTicketsValidatesProjects(): void {
    foreach ([['../x'], ['a b'], [5], ['ok', '']] as $projects) {
      $this->assertSame('Invalid project permalink in projects.', $this->error($this->call($this->server(), 'list_my_tickets', ['projects' => $projects])));
    }
    $this->assertSame('projects must be a list of project permalinks.', $this->error($this->call($this->server(), 'list_my_tickets', ['projects' => 'acme'])));
    $this->assertSame([], self::$api->requests());
  }

  public function testListMyTicketsCapsTheNumberOfProjects(): void {
    $projects = array_map(fn($i) => "p$i", range(1, 101));
    $result = $this->payload($this->call($this->server(), 'list_my_tickets', ['projects' => $projects]));
    $this->assertCount(100, $this->searches());
    $this->assertContains(['project' => 'p101', 'reason' => 'Not checked: more than 100 projects.'], $result['projects_skipped']);
  }

  public function testListMyTicketsStopsAtTheTimeLimit(): void {
    // One request at a time, so 'acme' cannot be queued behind 'slow'.
    $server = new CodebaseMCPServer('acme/peter', 'good-key', NULL, self::$api->url, 1.0, 1);
    $start = microtime(TRUE);
    $result = $this->payload($this->call($server, 'list_my_tickets', ['projects' => ['acme', 'slow']]));
    $this->assertLessThan(2.5, microtime(TRUE) - $start);

    $this->assertSame(2, $result['ticket_count'], 'finished projects are still returned');
    $this->assertSame(['slow'], array_column($result['projects_skipped'], 'project'));
    $this->assertSame(1, $result['projects_checked']);
  }

  public function testListMyTicketsSurfacesAuthenticationFailure(): void {
    $this->assertStringContainsString('(401)', $this->error($this->call($this->server(key: 'wrong'), 'list_my_tickets')));
  }

  public function testListTicketsReturnsCompactSummaries(): void {
    $result = $this->payload($this->call($this->server(), 'list_tickets', ['project' => 'acme']));
    $this->assertSame(['page' => 1, 'count' => 2, 'may_have_more' => FALSE], array_diff_key($result, ['tickets' => 1]));

    $tickets = $result['tickets'];
    $this->assertSame([12, 13], array_column($tickets, 'id'));
    $this->assertArrayNotHasKey('description', $tickets[0]);
    $this->assertArrayNotHasKey('project', $tickets[0], 'single project searches do not repeat the project');
    $this->assertSame(['id' => 12, 'summary' => 'Fix login', 'type' => 'bug', 'status' => 'In Progress', 'priority' => 'High',
      'assignee' => 'peter', 'milestone' => 'Rel 1', 'updated_at' => '2026-10-01T10:00:00Z'], $tickets[0]);
  }

  public function testGetTicketKeepsFullDetail(): void {
    $this->call($this->server(), 'get_ticket', ['project' => 'acme', 'ticket_id' => 12]);
    $this->assertSame('/acme/tickets/12.json', self::$api->requests()[0]['path']);
  }

  // Guidance for clients.

  public function testInitializeGivesInstructionsThatPointToTheCrossProjectTool(): void {
    $instructions = $this->rpc($this->server(), 'initialize')['result']['instructions'];
    $this->assertStringContainsString('list_my_tickets', $instructions);
    $this->assertStringContainsString('Do not call list_tickets for each project', $instructions);
  }

  public function testToolDescriptionsSteerTowardsTheRightTool(): void {
    $tools = array_column($this->rpc($this->server(), 'tools/list')['result']['tools'], NULL, 'name');
    $this->assertStringContainsString('ONE project', $tools['list_tickets']['description']);
    $this->assertStringContainsString('list_my_tickets', $tools['list_tickets']['description']);
    $this->assertStringContainsString('list_my_tickets', $tools['list_projects']['description']);
    $this->assertStringContainsString('ALL', $tools['list_my_tickets']['description']);
    $this->assertSame('array', $tools['list_my_tickets']['inputSchema']['properties']['projects']['type']);
    foreach (['list_tickets', 'list_my_tickets'] as $tool) {
      $this->assertStringContainsString('assignee:me', $tools[$tool]['description']);
      $this->assertStringContainsString('not-status:completed', $tools[$tool]['description']);
    }
    $this->assertStringContainsString('may_have_more', $tools['list_tickets']['description']);
    $this->assertSame('integer', $tools['list_tickets']['inputSchema']['properties']['page']['type']);
    $this->assertArrayNotHasKey('required', $tools['list_my_tickets']['inputSchema']);
  }

  // Errors.

  public function testUnknownToolIsAnError(): void {
    $this->assertSame('Unknown tool: nope', $this->error($this->call($this->server(), 'nope', ['project' => 'acme'])));
  }

  public function testRejectedCredentialsSurfaceTheApiError(): void {
    $message = $this->error($this->call($this->server(key: 'wrong'), 'list_projects'));
    $this->assertStringContainsString('Codebase API error (401)', $message);
  }

  public function testServerErrorsSurfaceTheStatus(): void {
    $message = $this->error($this->call($this->server(), 'get_milestones', ['project' => 'broken']));
    $this->assertStringContainsString('Codebase API error (500)', $message);
  }

  public function testSearchWithoutMatchesIsAnEmptyListNotAnError(): void {
    $result = $this->payload($this->call($this->server(), 'list_tickets', ['project' => 'quiet', 'query' => 'assignee:me status:open']));
    $this->assertSame(['page' => 1, 'count' => 0, 'may_have_more' => FALSE, 'tickets' => []], $result);
    // It confirmed the project exists before treating the 404 as empty.
    $this->assertSame(['/quiet/tickets.json', '/quiet.json'], array_column(self::$api->requests(), 'path'));
  }

  public function testListTicketsPaginates(): void {
    $server = $this->server();
    $first = $this->payload($this->call($server, 'list_tickets', ['project' => 'many']));
    $this->assertSame([1, 20, TRUE], [$first['page'], $first['count'], $first['may_have_more']]);

    $third = $this->payload($this->call($server, 'list_tickets', ['project' => 'many', 'page' => 3]));
    $this->assertSame([3, 5, FALSE], [$third['page'], $third['count'], $third['may_have_more']]);
    $this->assertSame(1040, $third['tickets'][0]['id'], 'page 3 starts at ticket 41');

    $requests = self::$api->requests();
    $this->assertArrayNotHasKey('page', $requests[0]['query'], 'the first page is the default');
    $this->assertSame('3', $requests[1]['query']['page']);
  }

  public function testPastTheLastPageIsEmptyWithoutProjectLookup(): void {
    $result = $this->payload($this->call($this->server(), 'list_tickets', ['project' => 'many', 'page' => 4]));
    $this->assertSame(['page' => 4, 'count' => 0, 'may_have_more' => FALSE, 'tickets' => []], $result);
    $this->assertSame(['/many/tickets.json'], array_column(self::$api->requests(), 'path'), 'no extra lookup past the end');
  }

  #[DataProvider('badPages')]
  public function testListTicketsRejectsInvalidPages(mixed $page): void {
    $this->assertSame('Invalid page.', $this->error($this->call($this->server(), 'list_tickets', ['project' => 'many', 'page' => $page])));
    $this->assertSame([], self::$api->requests());
  }

  public static function badPages(): array {
    return [[0], [-1], ['abc'], ['1.5'], [[2]], [100000], ['2 ']];
  }

  public function testSearchInAMissingProjectIsAnExplicitError(): void {
    $message = $this->error($this->call($this->server(), 'list_tickets', ['project' => 'missing']));
    $this->assertStringContainsString('Codebase API error (404)', $message);
    $this->assertStringContainsString('/missing was not found', $message);
    $this->assertStringNotContainsString('[]', $message);
  }

  public function testOtherSearchFailuresAreNotTreatedAsEmpty(): void {
    $this->assertStringContainsString('(500)', $this->error($this->call($this->server(), 'list_tickets', ['project' => 'broken'])));
    $this->assertStringContainsString('(401)', $this->error($this->call($this->server(key: 'wrong'), 'list_tickets', ['project' => 'quiet'])));
  }

  public function testNotFoundOnOtherToolsStaysExplicit(): void {
    $message = $this->error($this->call($this->server(), 'get_ticket', ['project' => 'missing', 'ticket_id' => 1]));
    $this->assertStringContainsString('/missing/tickets/1 was not found', $message);
  }

  public function testNotFoundIsExplainedForPostsToo(): void {
    $message = $this->error($this->call($this->server(), 'create_ticket', ['project' => 'missing', 'summary' => 'x']));
    $this->assertStringContainsString('was not found', $message);
  }

  public function testFailedToolCallsAreLoggedWithoutCredentials(): void {
    $log = tempnam(sys_get_temp_dir(), 'mcp-log');
    $previous = ini_set('error_log', $log);
    try {
      $this->call($this->server(), 'list_tickets', ['project' => 'missing']);
      $this->call($this->server(), 'get_milestones', ['project' => 'acme']);
      $this->rpc($this->server(), 'nope');
    }
    finally {
      ini_set('error_log', $previous === FALSE ? '' : $previous);
    }
    $logged = (string) file_get_contents($log);
    unlink($log);

    $this->assertStringContainsString('Tool call failed: tool=list_tickets project=missing', $logged);
    $this->assertStringContainsString('(404)', $logged);
    $this->assertSame(1, substr_count($logged, 'Tool call failed'), 'only failed tool calls are logged');
    $this->assertStringNotContainsString('good-key', $logged);
    $this->assertStringNotContainsString('acme/peter', $logged);
  }

  public function testUnreachableApiIsAnError(): void {
    $server = new CodebaseMCPServer('acme/peter', 'good-key', NULL, 'http://127.0.0.1:1');
    $this->assertStringContainsString('cURL error', $this->error($this->call($server, 'list_projects')));
  }

  public function testVerifyCredentials(): void {
    $this->server()->verifyCredentials();
    $this->expectExceptionMessageMatches('/Codebase API error \(401\)/');
    $this->server(key: 'wrong')->verifyCredentials();
  }

  public function testBaseUrlTrailingSlashIsIgnored(): void {
    $server = new CodebaseMCPServer('acme/peter', 'good-key', NULL, self::$api->url . '/');
    $this->payload($this->call($server, 'list_projects'));
    $this->assertSame('/projects.json', self::$api->requests()[0]['path']);
  }

  // Stdio transport.

  public function testStdioLoop(): void {
    $process = proc_open(
      [PHP_BINARY, __DIR__ . '/../codebase-mcp-server.php'],
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes,
      NULL,
      ['CODEBASE_USERNAME' => 'acme/peter', 'CODEBASE_API_KEY' => 'good-key', 'CODEBASE_API_URL' => self::$api->url, 'PATH' => getenv('PATH')],
    );
    fwrite($pipes[0], implode("\n", [
      json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']),
      json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']),
      'this is not json',
      json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'list_projects']]),
    ]) . "\n");
    fclose($pipes[0]);
    $lines = array_map(fn($l) => json_decode($l, TRUE), array_filter(explode("\n", stream_get_contents($pipes[1]))));
    proc_close($process);

    // One answer per request, none for the notification or the garbage line.
    $this->assertSame([1, 2], array_column($lines, 'id'));
    $this->assertArrayHasKey('result', $lines[1]);
  }

  public function testStdioEntryPointRequiresCredentials(): void {
    $process = proc_open(
      [PHP_BINARY, __DIR__ . '/../codebase-mcp-server.php'],
      [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes,
      NULL,
      ['PATH' => getenv('PATH')],
    );
    $stderr = stream_get_contents($pipes[2]);
    $this->assertSame(1, proc_close($process));
    $this->assertStringContainsString('Missing required environment variable', $stderr);
  }

}
