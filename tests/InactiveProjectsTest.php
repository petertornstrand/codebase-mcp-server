<?php

namespace petertornstrand\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use petertornstrand\CodebaseMCPServer;

class InactiveProjectsTest extends TestCase {

  /** The fake account's user id (see the profile in the fake API). */
  private const ME = 5;

  private static FakeCodebase $api;

  public static function setUpBeforeClass(): void {
    self::$api = new FakeCodebase();
  }

  protected function setUp(): void {
    self::$api->reset();
  }

  private function server(string $key = 'good-key', float $timeLimit = 20.0, bool $allowDestructive = TRUE): CodebaseMCPServer {
    return new CodebaseMCPServer('acme/peter', $key, NULL, self::$api->url, $timeLimit, 8, $allowDestructive);
  }

  private function call(string $tool, array $args = [], ?CodebaseMCPServer $server = NULL): array {
    return ($server ?? $this->server())->handle([
      'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args],
    ]);
  }

  private function payload(array $response): array {
    $this->assertArrayNotHasKey('error', $response, json_encode($response));
    return json_decode($response['result']['content'][0]['text'], TRUE);
  }

  private function error(array $response): string {
    $this->assertArrayHasKey('error', $response);
    return $response['error']['message'];
  }

  private function find(array $args = []): array {
    return $this->payload($this->call('find_inactive_projects', $args));
  }

  private function unassign(array $projects, bool|string|int $confirm = FALSE, array $extra = []): array {
    return $this->payload($this->call('unassign_from_projects', ['projects' => $projects, 'confirm' => $confirm] + $extra));
  }

  /** @return string[] */
  private function names(array $projects): array {
    $names = array_column($projects, 'project');
    sort($names);
    return $names;
  }

  /** The outcomes of an unassign call, keyed by project. */
  private function outcomes(array $result): array {
    return array_column($result['results'], NULL, 'project');
  }

  private function posts(): array {
    return array_values(array_filter(self::$api->requests(), fn($r) => $r['method'] === 'POST'));
  }

  /** The ids of the users currently assigned to a project, sorted. */
  private function assignedTo(string $project): array {
    $users = $this->payload($this->call('get_project_users', ['project' => $project]));
    $ids = array_map(fn($user) => $user['user']['id'], $users);
    sort($ids);
    return $ids;
  }

  // Finding inactive projects.

  #[DataProvider('invalidMonths')]
  public function testMonthsMustBeAtLeastTwelve(mixed $months, string $message): void {
    $this->assertSame($message, $this->error($this->call('find_inactive_projects', ['months' => $months])));
    $this->assertSame([], self::$api->requests(), 'nothing is looked up for invalid input');
  }

  public static function invalidMonths(): array {
    return [
      'eleven' => [11, 'months must be at least 12.'],
      'one' => [1, 'months must be at least 12.'],
      'zero' => [0, 'months must be at least 12.'],
      'numeric string' => ['6', 'months must be at least 12.'],
      'negative' => [-12, 'months must be a whole number.'],
      'fraction' => [12.5, 'months must be a whole number.'],
      'text' => ['a year', 'months must be a whole number.'],
      'list' => [[12], 'months must be a whole number.'],
      'too many' => [121, 'months must be at most 120.'],
    ];
  }

  public function testClassifiesEveryAssignedProject(): void {
    $result = $this->find();

    $this->assertSame(12, $result['months']);
    $this->assertSame(gmdate('Y-m-d', strtotime('-12 months')), $result['cutoff']);
    $this->assertSame(1, $result['archived_projects_ignored']);

    // Inactive: nothing assigned and nothing in the feed since the cutoff.
    $this->assertSame(
      ['ia-flaky', 'ia-ignored', 'ia-inactive', 'ia-oldevent', 'ia-rejects', 'ia-solo', 'ia-stubborn', 'ia-ticket-old'],
      $this->names($result['inactive_projects'])
    );
    // Active: an open ticket, a recently updated ticket, or an event by me
    // (acme and beta return tickets for any search).
    $this->assertSame(6, $result['active_projects']);
    // Could not be checked completely: never reported as inactive.
    $this->assertSame(['ia-busy', 'ia-denied', 'ia-ticket-error'], $this->names($result['undetermined']));
    $this->assertSame(17, $result['projects_checked']);
  }

  public function testUndeterminedReasonsSayWhy(): void {
    $reasons = array_column($this->find()['undetermined'], 'reason', 'project');
    $this->assertStringContainsString('(403)', $reasons['ia-denied']);
    $this->assertStringContainsString('(500)', $reasons['ia-ticket-error']);
    $this->assertStringContainsString('Busy project', $reasons['ia-busy']);
  }

  public function testInactiveProjectsComeWithTheNameAndTheReason(): void {
    $inactive = array_column($this->find()['inactive_projects'], NULL, 'project');
    $this->assertSame('Ia-inactive', $inactive['ia-inactive']['name']);
    $this->assertSame(
      'No activity by you since ' . gmdate('Y-m-d', strtotime('-12 months')) . ', and no tickets assigned to you.',
      $inactive['ia-inactive']['reason']
    );
  }

  public function testTheFeedIsOnlyAskedForEventsSinceTheCutoff(): void {
    $this->find(['months' => 18]);
    $feeds = array_values(array_filter(self::$api->requests(), fn($r) => $r['path'] === '/ia-inactive/activity.json'));
    $this->assertCount(1, $feeds);
    $this->assertSame(gmdate('Y-m-d', strtotime('-18 months')), substr($feeds[0]['query']['since'], 0, 10));
    $this->assertStringEndsWith('+0000', $feeds[0]['query']['since']);
  }

  public function testALongerPeriodCanTurnAnInactiveProjectActive(): void {
    // ia-oldevent has one event by me, 500 days ago.
    $this->assertContains('ia-oldevent', $this->names($this->find(['months' => 12])['inactive_projects']));
    $this->assertNotContains('ia-oldevent', $this->names($this->find(['months' => 24])['inactive_projects']));
  }

  public function testTicketsOlderThanTheCutoffDoNotCount(): void {
    // ia-ticket-old: assigned a ticket, closed and last updated 500 days ago.
    $this->assertContains('ia-ticket-old', $this->names($this->find()['inactive_projects']));
    $this->assertNotContains('ia-ticket-old', $this->names($this->find(['months' => 24])['inactive_projects']));
  }

  public function testFollowsTheFeedUntilItFindsAnEventByTheUser(): void {
    $this->find();
    $pages = array_map(
      fn($r) => (int) ($r['query']['page'] ?? 1),
      array_values(array_filter(self::$api->requests(), fn($r) => $r['path'] === '/ia-late/activity.json'))
    );
    sort($pages);
    $this->assertSame([1, 2, 3], $pages, 'my event is on page 3, so no page 4');
  }

  public function testStopsReadingABusyFeedAtThePageLimit(): void {
    $this->find();
    $pages = array_filter(self::$api->requests(), fn($r) => $r['path'] === '/ia-busy/activity.json');
    $this->assertCount(15, $pages);
  }

  public function testEasyProjectsAreDecidedInOneRound(): void {
    $this->find();
    foreach (['ia-event', 'ia-inactive', 'ia-ticket-open'] as $project) {
      $feeds = array_filter(self::$api->requests(), fn($r) => $r['path'] === "/$project/activity.json");
      $this->assertCount(1, $feeds, $project);
    }
    // Ticket checks are made once per project, not once per feed page.
    $tickets = array_filter(self::$api->requests(), fn($r) => $r['path'] === '/ia-late/tickets.json');
    $this->assertCount(2, $tickets);
  }

  public function testArchivedProjectsAreNeverQueried(): void {
    $this->find();
    $this->assertEmpty(array_filter(self::$api->requests(), fn($r) => str_starts_with($r['path'], '/old/')));
  }

  public function testFindingNeverChangesAnything(): void {
    $this->find();
    $this->assertSame([], $this->posts());
    $this->assertSame([], array_filter(self::$api->requests(), fn($r) => !in_array($r['method'], ['GET'], TRUE)));
  }

  public function testTicketsAreSearchedForTheCallerAndFeedsForTheirOwnEvents(): void {
    $this->find();
    $queries = array_unique(array_column(array_column(array_filter(self::$api->requests(), fn($r) => str_ends_with($r['path'], '/tickets.json') && str_starts_with($r['path'], '/ia-')), 'query'), 'query'));
    sort($queries);
    $this->assertSame(['assignee:me sort:updated_at order:desc', 'assignee:me status:open'], $queries);
  }

  public function testNothingIsCalledInactiveWhenTheTimeRunsOut(): void {
    $result = $this->payload($this->call('find_inactive_projects', [], $this->server(timeLimit: 0.0)));
    $this->assertSame([], $result['inactive_projects']);
    $this->assertSame(0, $result['active_projects']);
    $this->assertCount(17, $result['undetermined']);
    $this->assertStringContainsString('time limit', strtolower($result['undetermined'][0]['reason']));
  }

  public function testAuthenticationFailureIsAnError(): void {
    $this->assertStringContainsString('(401)', $this->error($this->call('find_inactive_projects', [], $this->server('wrong'))));
  }

  // Removing the user: nothing happens without confirmation.

  public function testWithoutConfirmationItIsADryRun(): void {
    $result = $this->unassign(['ia-inactive', 'ia-oldevent', 'ia-event']);

    $this->assertTrue($result['dry_run']);
    $outcomes = $this->outcomes($result);
    $this->assertSame('would_unassign', $outcomes['ia-inactive']['status']);
    $this->assertSame('would_unassign', $outcomes['ia-oldevent']['status']);
    $this->assertSame('skipped', $outcomes['ia-event']['status']);
    $this->assertSame([], $this->posts(), 'nothing is posted');
    $this->assertSame([5, 6, 7], $this->assignedTo('ia-inactive'));
  }

  #[DataProvider('notConfirmed')]
  public function testOnlyTheBooleanTrueConfirms(mixed $confirm): void {
    $result = $this->payload($this->call('unassign_from_projects', ['projects' => ['ia-inactive'], 'confirm' => $confirm]));
    $this->assertTrue($result['dry_run']);
    $this->assertSame([], $this->posts());
  }

  public static function notConfirmed(): array {
    return [['true'], ['yes'], [1], ['1'], [NULL], [FALSE], [[]], ['']];
  }

  public function testConfirmationIsOffByDefault(): void {
    $result = $this->payload($this->call('unassign_from_projects', ['projects' => ['ia-inactive']]));
    $this->assertTrue($result['dry_run']);
    $this->assertSame([], $this->posts());
  }

  // Removing the user: what a confirmed removal does.

  public function testRemovesOnlyTheCallerAndKeepsEveryoneElse(): void {
    $result = $this->unassign(['ia-inactive'], TRUE);

    $this->assertFalse($result['dry_run']);
    $outcome = $this->outcomes($result)['ia-inactive'];
    $this->assertSame('unassigned', $outcome['status']);
    $this->assertStringContainsString('2 other user(s) remain', $outcome['detail']);

    $posts = $this->posts();
    $this->assertCount(1, $posts);
    $this->assertSame('/ia-inactive/assignments', $posts[0]['path'], 'the documented XML endpoint, not .json');
    $this->assertSame('application/xml', $posts[0]['content_type']);
    $this->assertSame('<users><user><id>6</id></user><user><id>7</id></user></users>', $posts[0]['raw']);
    $this->assertSame([6, 7], $this->assignedTo('ia-inactive'));
  }

  public function testSeveralProjectsAtOnce(): void {
    $result = $this->unassign(['ia-inactive', 'ia-oldevent', 'ia-event'], TRUE);
    $outcomes = $this->outcomes($result);
    $this->assertSame('unassigned', $outcomes['ia-inactive']['status']);
    $this->assertSame('unassigned', $outcomes['ia-oldevent']['status']);
    $this->assertSame('skipped', $outcomes['ia-event']['status']);
    $this->assertCount(2, $this->posts());
    $this->assertSame([5, 6, 7], $this->assignedTo('ia-event'), 'an active project is untouched');
  }

  public function testDuplicatesAreChangedOnce(): void {
    $result = $this->unassign(['ia-inactive', 'ia-inactive'], TRUE);
    $this->assertCount(1, $result['results']);
    $this->assertCount(1, $this->posts());
  }

  public function testItIsCheckedAgainBeforeRemoving(): void {
    // Active projects, and ones that could not be checked, are never changed,
    // whatever list the caller passes.
    $result = $this->unassign(['ia-event', 'ia-late', 'ia-ticket-open', 'ia-ticket-recent', 'ia-busy', 'ia-denied', 'ia-ticket-error'], TRUE);

    $this->assertSame([], $this->posts());
    foreach ($result['results'] as $outcome) {
      $this->assertSame('skipped', $outcome['status'], $outcome['project']);
    }
    $outcomes = $this->outcomes($result);
    $this->assertStringStartsWith('Not inactive:', $outcomes['ia-event']['detail']);
    $this->assertStringStartsWith('Could not be determined:', $outcomes['ia-busy']['detail']);
    $this->assertStringStartsWith('Could not be determined:', $outcomes['ia-denied']['detail']);
  }

  public function testTheInactivityPeriodIsCheckedAgainWithTheGivenMonths(): void {
    // Inactive for 12 months, but not for 24.
    $result = $this->unassign(['ia-oldevent'], TRUE, ['months' => 24]);
    $this->assertSame('skipped', $this->outcomes($result)['ia-oldevent']['status']);
    $this->assertSame([], $this->posts());
  }

  public function testProjectsTheUserIsNotAssignedToAreNotTouched(): void {
    $result = $this->unassign(['not-mine', 'old'], TRUE);
    $outcomes = $this->outcomes($result);
    $this->assertSame('skipped', $outcomes['not-mine']['status']);
    $this->assertSame('skipped', $outcomes['old']['status'], 'archived');
    $this->assertSame([], $this->posts());
    $this->assertEmpty(array_filter(self::$api->requests(), fn($r) => str_starts_with($r['path'], '/not-mine') || str_starts_with($r['path'], '/old/')));
  }

  public function testTheOnlyUserOfAProjectIsNotRemoved(): void {
    $outcome = $this->outcomes($this->unassign(['ia-solo'], TRUE))['ia-solo'];
    $this->assertSame('skipped', $outcome['status']);
    $this->assertStringContainsString('only user', $outcome['detail']);
    $this->assertSame([], $this->posts());
  }

  // Removing the user: when Codebase does not do what was asked.

  public function testRestoresTheOriginalUsersIfAnotherUserWentMissing(): void {
    $outcome = $this->outcomes($this->unassign(['ia-flaky'], TRUE))['ia-flaky'];

    $this->assertSame('failed', $outcome['status']);
    $this->assertStringContainsString('original users were restored', $outcome['detail']);
    $posts = $this->posts();
    $this->assertCount(2, $posts);
    $this->assertSame('<users><user><id>6</id></user><user><id>7</id></user></users>', $posts[0]['raw'], 'first the intended change');
    $this->assertSame('<users><user><id>5</id></user><user><id>6</id></user><user><id>7</id></user></users>', $posts[1]['raw'], 'then the original list');
    $this->assertSame([5, 6, 7], $this->assignedTo('ia-flaky'), 'nobody was lost');
  }

  public function testSaysLoudlyIfTheRestoreFailsToo(): void {
    $outcome = $this->outcomes($this->unassign(['ia-stubborn'], TRUE))['ia-stubborn'];

    $this->assertSame('failed', $outcome['status']);
    $this->assertStringContainsString('RESTORE FAILED', $outcome['detail']);
    $this->assertStringContainsString('5, 6, 7', $outcome['detail'], 'the original ids, so an administrator can fix it');
    $this->assertCount(2, $this->posts(), 'one restore attempt, no loop');
  }

  public function testReportsAChangeCodebaseSilentlyIgnored(): void {
    $outcome = $this->outcomes($this->unassign(['ia-ignored'], TRUE))['ia-ignored'];
    $this->assertSame('failed', $outcome['status']);
    $this->assertStringContainsString('did not change', $outcome['detail']);
    $this->assertCount(1, $this->posts(), 'nothing to restore');
  }

  public function testReportsARejectedChange(): void {
    $outcome = $this->outcomes($this->unassign(['ia-rejects'], TRUE))['ia-rejects'];
    $this->assertSame('failed', $outcome['status']);
    $this->assertStringContainsString('(403)', $outcome['detail']);
    $this->assertSame([5, 6, 7], $this->assignedTo('ia-rejects'));
  }

  public function testOneFailureDoesNotStopTheOtherProjects(): void {
    $outcomes = $this->outcomes($this->unassign(['ia-rejects', 'ia-inactive'], TRUE));
    $this->assertSame('failed', $outcomes['ia-rejects']['status']);
    $this->assertSame('unassigned', $outcomes['ia-inactive']['status']);
  }

  // Input validation.

  #[DataProvider('invalidProjectLists')]
  public function testRejectsInvalidProjectLists(mixed $projects, string $message): void {
    $this->assertSame($message, $this->error($this->call('unassign_from_projects', ['projects' => $projects, 'confirm' => TRUE])));
    $this->assertSame([], self::$api->requests(), 'nothing is looked up or changed');
  }

  public static function invalidProjectLists(): array {
    return [
      'missing' => [NULL, 'projects must be a non-empty list of project permalinks.'],
      'empty' => [[], 'projects must be a non-empty list of project permalinks.'],
      'string' => ['ia-inactive', 'projects must be a non-empty list of project permalinks.'],
      'map' => [['a' => 'ia-inactive'], 'projects must be a non-empty list of project permalinks.'],
      'path traversal' => [['../x'], 'Invalid project permalink in projects.'],
      'space' => [['a b'], 'Invalid project permalink in projects.'],
      'number' => [[5], 'Invalid project permalink in projects.'],
      'empty name' => [[''], 'Invalid project permalink in projects.'],
      'too many' => [array_map(fn($i) => "p$i", range(1, 21)), 'At most 20 projects can be changed in one call.'],
    ];
  }

  public function testRejectsTooFewMonthsBeforeDoingAnything(): void {
    $this->assertSame('months must be at least 12.', $this->error($this->call('unassign_from_projects', ['projects' => ['ia-inactive'], 'months' => 6, 'confirm' => TRUE])));
    $this->assertSame([], self::$api->requests());
  }


  // The allow_destructive setting.

  private function disabledServer(): CodebaseMCPServer {
    return $this->server(allowDestructive: FALSE);
  }

  private function toolNames(CodebaseMCPServer $server): array {
    return array_column($server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['result']['tools'], 'name');
  }

  public function testDestructiveToolsAreHiddenUnlessAllowed(): void {
    $this->assertNotContains('unassign_from_projects', $this->toolNames($this->disabledServer()));
    $this->assertContains('unassign_from_projects', $this->toolNames($this->server()));
    // The read-only tool is always there.
    $this->assertContains('find_inactive_projects', $this->toolNames($this->disabledServer()));
    $this->assertCount(count($this->toolNames($this->server())) - 1, $this->toolNames($this->disabledServer()), 'only the destructive tool is hidden');
  }

  public function testTheServerDefaultsToNotAllowingDestructiveTools(): void {
    $server = new CodebaseMCPServer('acme/peter', 'good-key', NULL, self::$api->url);
    $this->assertNotContains('unassign_from_projects', $this->toolNames($server));
    $this->assertStringContainsString('disabled', $this->error($this->call('unassign_from_projects', ['projects' => ['ia-inactive'], 'confirm' => TRUE], $server)));
  }

  #[DataProvider('everyKindOfCall')]
  public function testADisabledToolRefusesToRunAndTouchesNothing(array $args): void {
    $message = $this->error($this->call('unassign_from_projects', $args, $this->disabledServer()));
    $this->assertStringContainsString('The tool unassign_from_projects is disabled', $message);
    $this->assertStringContainsString('allow_destructive', $message);
    $this->assertStringContainsString('Nothing was changed', $message);
    $this->assertSame([], self::$api->requests(), 'not even a read');
  }

  public static function everyKindOfCall(): array {
    return [
      'confirmed' => [['projects' => ['ia-inactive'], 'confirm' => TRUE]],
      'dry run' => [['projects' => ['ia-inactive']]],
      'no arguments' => [[]],
      'invalid arguments' => [['projects' => 'x', 'months' => 1]],
    ];
  }

  public function testTheRefusalIsLoggedLikeAnyFailedToolCall(): void {
    // A client that keeps calling a hidden tool shows up in the error log.
    $log = tempnam(sys_get_temp_dir(), 'mcp-log');
    $previous = ini_set('error_log', $log);
    try {
      $this->call('unassign_from_projects', ['projects' => ['ia-inactive'], 'confirm' => TRUE], $this->disabledServer());
    }
    finally {
      ini_set('error_log', $previous === FALSE ? '' : $previous);
    }
    $logged = (string) file_get_contents($log);
    unlink($log);
    $this->assertStringContainsString('Tool call failed: tool=unassign_from_projects', $logged);
  }

  public function testFindingStillWorksAndSaysRemovalIsDisabled(): void {
    $result = $this->payload($this->call('find_inactive_projects', [], $this->disabledServer()));
    $this->assertNotEmpty($result['inactive_projects']);
    $this->assertStringContainsString('disabled on this server', $result['next_step']);
    $this->assertStringNotContainsString('call unassign_from_projects', $result['next_step']);
  }

  public function testInstructionsOnlyMentionRemovalWhenItIsAllowed(): void {
    $initialize = fn(CodebaseMCPServer $s) => $s->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])['result']['instructions'];
    $off = $initialize($this->disabledServer());
    $this->assertStringContainsString('find_inactive_projects', $off);
    $this->assertStringNotContainsString('unassign_from_projects', $off);
    $this->assertStringNotContainsString('destructive', $off);
    $this->assertStringContainsString('unassign_from_projects', $initialize($this->server()));
  }

  public function testTheServiceItselfRefusesToRemoveWhenNotAllowed(): void {
    // Even if the server layer were bypassed, the class does not act.
    $called = FALSE;
    $touch = function () use (&$called) { $called = TRUE; return []; };
    $service = new \petertornstrand\InactiveProjects($touch, $touch, $touch, 20.0, FALSE);
    try {
      $service->unassign(['ia-inactive'], 12, TRUE);
      $this->fail('Expected an exception');
    }
    catch (\Exception $e) {
      $this->assertStringContainsString('disabled', $e->getMessage());
    }
    $this->assertFalse($called, 'nothing was read or written');
  }

  #[DataProvider('environmentValues')]
  public function testTheCommandLineServerReadsTheSettingFromTheEnvironment(?string $value, bool $expected): void {
    $env = ['CODEBASE_USERNAME' => 'acme/peter', 'CODEBASE_API_KEY' => 'good-key', 'PATH' => getenv('PATH')];
    if ($value !== NULL) {
      $env['CODEBASE_ALLOW_DESTRUCTIVE'] = $value;
    }
    $process = proc_open([PHP_BINARY, __DIR__ . '/../codebase-mcp-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, NULL, $env);
    fwrite($pipes[0], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']) . "\n");
    fclose($pipes[0]);
    $response = json_decode(stream_get_contents($pipes[1]), TRUE);
    proc_close($process);

    $this->assertSame($expected, in_array('unassign_from_projects', array_column($response['result']['tools'], 'name'), TRUE), (string) $value);
  }

  public static function environmentValues(): array {
    return [
      'not set' => [NULL, FALSE],
      'empty' => ['', FALSE],
      'zero' => ['0', FALSE],
      'false' => ['false', FALSE],
      'yes' => ['yes', FALSE],
      'on' => ['on', FALSE],
      'one' => ['1', TRUE],
      'true' => ['true', TRUE],
      'TRUE' => ['TRUE', TRUE],
    ];
  }

  // What the client is told.

  public function testToolsAreMarkedWithTheirSafety(): void {
    $tools = array_column($this->server()->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['result']['tools'], NULL, 'name');

    $this->assertTrue($tools['find_inactive_projects']['annotations']['readOnlyHint']);
    $this->assertSame(12, $tools['find_inactive_projects']['inputSchema']['properties']['months']['minimum']);

    $unassign = $tools['unassign_from_projects'];
    $this->assertFalse($unassign['annotations']['readOnlyHint']);
    $this->assertTrue($unassign['annotations']['destructiveHint']);
    $this->assertStringStartsWith('DESTRUCTIVE', $unassign['description']);
    $this->assertStringContainsString('dry run', $unassign['description']);
    $this->assertStringContainsString('explicitly agreed', $unassign['description']);
    $this->assertSame(['projects'], $unassign['inputSchema']['required']);
    $this->assertFalse($unassign['inputSchema']['properties']['confirm']['default']);
    $this->assertSame(12, $unassign['inputSchema']['properties']['months']['minimum']);
    $this->assertSame(20, $unassign['inputSchema']['properties']['projects']['maxItems']);
  }

  public function testInstructionsTellClientsToGetExplicitAgreement(): void {
    $instructions = $this->server()->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])['result']['instructions'];
    $this->assertStringContainsString('find_inactive_projects', $instructions);
    $this->assertStringContainsString('destructive', $instructions);
    $this->assertStringContainsString('explicitly agreed', $instructions);
  }

  public function testFindingPointsToTheNextStep(): void {
    $this->assertStringContainsString('unassign_from_projects', $this->find()['next_step']);
  }

}
