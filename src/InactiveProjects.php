<?php

namespace petertornstrand;

/**
 * Finds projects the user is assigned to but has not been active in, and
 * removes the user from them.
 *
 * Activity is any of: an open ticket assigned to the user, a ticket assigned
 * to the user and updated since the cutoff, or an event by the user in the
 * project's activity feed since the cutoff. A project is only called
 * inactive when all of that was checked completely; anything uncertain is
 * reported as undetermined, never as inactive.
 *
 * Removing a user is the one dangerous operation: Codebase has no "remove"
 * call, only one that replaces the whole list of users of a project. The
 * code therefore only ever removes the calling user, posts everyone else
 * back unchanged, verifies the result, and restores the original list if it
 * differs from what was intended.
 */
class InactiveProjects {

  public const MIN_MONTHS = 12;

  private const MAX_MONTHS = 120;

  /** Most projects examined in one call. */
  private const MAX_PROJECTS = 100;

  /** Most projects removed in one call, to limit the blast radius. */
  private const MAX_UNASSIGN = 20;

  /** Codebase returns this many activity events per page. */
  private const FEED_PAGE_SIZE = 20;

  /** Most feed pages read per project. */
  private const MAX_FEED_PAGES = 15;

  /**
   * @param callable $get
   *   fn(string $path, array $params = []): array. A JSON GET.
   * @param callable $postXml
   *   fn(string $path, string $xml): void. An XML POST; throws on failure.
   * @param callable $fetcher
   *   fn(float $timeLimit): ParallelFetcher.
   * @param float $timeLimit
   *   Seconds allowed for examining all projects.
   * @param ?callable $now
   *   fn(): int. The current time (for tests).
   */
  public function __construct(
    private $get,
    private $postXml,
    private $fetcher,
    private float $timeLimit = 20.0,
    private $now = NULL,
  ) {}

  private function now(): int {
    return $this->now ? ($this->now)() : time();
  }

  /**
   * Lists the assigned projects without activity in the last $months months.
   *
   * @throws \Exception If months is invalid or the profile cannot be read.
   */
  public function find(mixed $months): array {
    $months = $this->months($months);
    $cutoff = $this->cutoff($months);
    [$myId, $projects, $archived] = $this->assignedProjects();

    $skipped = [];
    if (count($projects) > self::MAX_PROJECTS) {
      foreach (array_slice($projects, self::MAX_PROJECTS, NULL, TRUE) as $permalink => $name) {
        $skipped[$permalink] = ['state' => 'undetermined', 'reason' => 'Not checked: more than ' . self::MAX_PROJECTS . ' projects.'];
      }
      $projects = array_slice($projects, 0, self::MAX_PROJECTS, TRUE);
    }

    $results = $this->classify($projects, $myId, $cutoff) + $skipped;
    $date = gmdate('Y-m-d', $cutoff);

    $inactive = $undetermined = [];
    $active = 0;
    foreach ($results as $permalink => $result) {
      $name = $projects[$permalink] ?? $permalink;
      if ($result['state'] === 'inactive') {
        $inactive[] = ['project' => $permalink, 'name' => $name, 'reason' => "No activity by you since $date, and no tickets assigned to you."];
      }
      elseif ($result['state'] === 'active') {
        $active++;
      }
      else {
        $undetermined[] = ['project' => $permalink, 'name' => $name, 'reason' => $result['reason']];
      }
    }

    return [
      'months' => $months,
      'cutoff' => $date,
      'projects_checked' => count($results),
      'active_projects' => $active,
      'inactive_projects' => $inactive,
      'undetermined' => $undetermined,
      'archived_projects_ignored' => $archived,
      'next_step' => $inactive
        ? 'To remove the user from some of these projects, show this list and ask for explicit confirmation, then call unassign_from_projects with those projects and confirm true.'
        : 'Nothing to remove.',
    ];
  }

  /**
   * Removes the calling user from the given projects, if still inactive.
   *
   * Without confirm === TRUE nothing is changed; the result lists what would
   * be done.
   *
   * @throws \Exception If the arguments are invalid or the profile cannot be read.
   */
  public function unassign(mixed $permalinks, mixed $months, mixed $confirm): array {
    $months = $this->months($months);
    if (!is_array($permalinks) || !array_is_list($permalinks) || $permalinks === []) {
      throw new \Exception('projects must be a non-empty list of project permalinks.');
    }
    foreach ($permalinks as $permalink) {
      if (!is_string($permalink) || !preg_match('/^[A-Za-z0-9_-]+$/', $permalink)) {
        throw new \Exception('Invalid project permalink in projects.');
      }
    }
    $permalinks = array_values(array_unique($permalinks));
    if (count($permalinks) > self::MAX_UNASSIGN) {
      throw new \Exception('At most ' . self::MAX_UNASSIGN . ' projects can be changed in one call.');
    }

    $cutoff = $this->cutoff($months);
    [$myId, $assigned] = $this->assignedProjects();

    $outcomes = [];
    $candidates = [];
    foreach ($permalinks as $permalink) {
      if (isset($assigned[$permalink])) {
        $candidates[$permalink] = $assigned[$permalink];
      }
      else {
        $outcomes[] = $this->outcome($permalink, 'skipped', 'You are not assigned to this project, or it is archived.');
      }
    }

    // Activity is checked again now, so a stale list cannot cause a removal.
    $eligible = [];
    foreach ($this->classify($candidates, $myId, $cutoff) as $permalink => $result) {
      if ($result['state'] === 'inactive') {
        $eligible[] = $permalink;
      }
      else {
        $outcomes[] = $this->outcome($permalink, 'skipped', ($result['state'] === 'active' ? 'Not inactive: ' : 'Could not be determined: ') . $result['reason']);
      }
    }

    $dryRun = $confirm !== TRUE;
    foreach ($eligible as $permalink) {
      $outcomes[] = $dryRun
        ? $this->outcome($permalink, 'would_unassign', 'Inactive since ' . gmdate('Y-m-d', $cutoff) . '. Nothing was changed.')
        : $this->removeUser($permalink, $myId);
    }

    return [
      'dry_run' => $dryRun,
      'message' => $dryRun
        ? 'Nothing was changed. To remove the user from the projects listed as would_unassign, call again with confirm true after the user has explicitly agreed.'
        : 'Done. See each project for the outcome.',
      'results' => $outcomes,
    ];
  }

  private function outcome(string $project, string $status, string $detail): array {
    return ['project' => $project, 'status' => $status, 'detail' => $detail];
  }

  private function months(mixed $months): int {
    $months ??= self::MIN_MONTHS;
    if (!is_scalar($months) || !preg_match('/^\d+$/', (string) $months)) {
      throw new \Exception('months must be a whole number.');
    }
    $months = (int) $months;
    if ($months < self::MIN_MONTHS) {
      throw new \Exception('months must be at least ' . self::MIN_MONTHS . '.');
    }
    if ($months > self::MAX_MONTHS) {
      throw new \Exception('months must be at most ' . self::MAX_MONTHS . '.');
    }
    return $months;
  }

  private function cutoff(int $months): int {
    return (new \DateTimeImmutable('@' . $this->now()))->modify("-{$months} months")->getTimestamp();
  }

  /**
   * The user's id and active projects, from the profile.
   *
   * @return array{0: int, 1: array<string, string>, 2: int}
   *   The user id, permalink => name for active projects, and the number of
   *   archived projects left out.
   */
  private function assignedProjects(): array {
    $profile = ($this->get)('/profile');
    $user = isset($profile['user']) && is_array($profile['user']) ? $profile['user'] : $profile;
    if (!isset($user['id']) || !is_int($user['id'])) {
      throw new \Exception('Unable to read the user profile from Codebase.');
    }

    $projects = [];
    $archived = 0;
    foreach (($user['assignments'] ?? []) as $project) {
      $project = is_array($project) && isset($project['project']) && is_array($project['project']) ? $project['project'] : $project;
      $permalink = is_array($project) ? ($project['permalink'] ?? NULL) : NULL;
      if (!is_string($permalink) || !preg_match('/^[A-Za-z0-9_-]+$/', $permalink)) {
        continue;
      }
      if (strtolower((string) ($project['status'] ?? 'active')) !== 'active') {
        $archived++;
        continue;
      }
      $projects[$permalink] = (string) ($project['name'] ?? $permalink);
    }
    return [$user['id'], $projects, $archived];
  }

  /**
   * Works out, for each project, whether the user has been active.
   *
   * @param array<string, string> $projects
   *   Permalink => name.
   *
   * @return array<string, array{state: string, reason: string}>
   *   state is active, inactive or undetermined, with the reason.
   */
  private function classify(array $projects, int $myId, int $cutoff): array {
    $since = gmdate('Y-m-d H:i:s', $cutoff) . ' +0000';
    $deadline = microtime(TRUE) + $this->timeLimit;
    $results = [];
    // Permalink => the feed page to read next.
    $round = array_fill_keys(array_keys($projects), 1);

    while ($round) {
      $paths = [];
      foreach ($round as $permalink => $page) {
        $paths["$permalink|feed"] = "/$permalink/activity.json?" . http_build_query(['since' => $since] + ($page > 1 ? ['page' => $page] : []));
        if ($page === 1) {
          $paths["$permalink|open"] = "/$permalink/tickets.json?" . http_build_query(['query' => 'assignee:me status:open']);
          $paths["$permalink|recent"] = "/$permalink/tickets.json?" . http_build_query(['query' => 'assignee:me sort:updated_at order:desc']);
        }
      }

      $fetched = ($this->fetcher)(max(0.0, $deadline - microtime(TRUE)))->get($paths);
      $next = [];
      foreach ($round as $permalink => $page) {
        $decision = $this->decide($permalink, $page, $fetched, $myId, $cutoff);
        if ($decision === NULL) {
          if ($page >= self::MAX_FEED_PAGES) {
            $results[$permalink] = ['state' => 'undetermined', 'reason' => 'Busy project: more than ' . (self::MAX_FEED_PAGES * self::FEED_PAGE_SIZE) . ' events since the cutoff and none by you in the newest ones, so the rest was not read.'];
          }
          else {
            $next[$permalink] = $page + 1;
          }
        }
        else {
          $results[$permalink] = $decision;
        }
      }

      if ($next && $deadline - microtime(TRUE) <= 0.05) {
        foreach ($next as $permalink => $page) {
          $results[$permalink] = ['state' => 'undetermined', 'reason' => 'Time limit reached before the activity feed was fully read.'];
        }
        break;
      }
      $round = $next;
    }

    // Keep the caller's order.
    return array_replace(array_fill_keys(array_keys($projects), NULL), $results);
  }

  /**
   * Decides one project from the responses of one round, or returns NULL if
   * the next page of the feed is needed.
   */
  private function decide(string $permalink, int $page, array $fetched, int $myId, int $cutoff): ?array {
    $failures = [];

    // Tickets: only fetched on the first page. A 404 means no matches.
    $open = $recent = [];
    if ($page === 1) {
      $open = $this->listOrFailure($fetched["$permalink|open"], TRUE);
      if (is_string($open)) {
        $failures[] = $open;
        $open = [];
      }
      $recent = $this->listOrFailure($fetched["$permalink|recent"], TRUE);
      if (is_string($recent)) {
        $failures[] = $recent;
        $recent = [];
      }
    }

    $feed = $this->listOrFailure($fetched["$permalink|feed"], FALSE);
    if (is_string($feed)) {
      $failures[] = $feed;
      $feed = [];
    }

    // Evidence of activity counts even if another check failed.
    if ($open) {
      return ['state' => 'active', 'reason' => 'You have an open ticket assigned.'];
    }
    $updated = $this->latest($recent, fn($t) => $t['updated_at'] ?? NULL);
    if ($updated !== NULL && $updated >= $cutoff) {
      return ['state' => 'active', 'reason' => 'A ticket assigned to you was updated ' . gmdate('Y-m-d', $updated) . '.'];
    }
    $mine = array_values(array_filter($feed, fn($e) => ($e['user_id'] ?? NULL) === $myId));
    $last = $this->latest($mine, fn($e) => $e['timestamp'] ?? NULL);
    if ($mine) {
      return ['state' => 'active', 'reason' => $last !== NULL ? 'You were active on ' . gmdate('Y-m-d', $last) . '.' : 'You were active in the activity feed.'];
    }

    if ($failures) {
      return ['state' => 'undetermined', 'reason' => $failures[0]];
    }
    // A short page is the last one: the feed has been read to the cutoff.
    return count($feed) < self::FEED_PAGE_SIZE
      ? ['state' => 'inactive', 'reason' => 'No activity.']
      : NULL;
  }

  /**
   * The decoded list of a response, or a message describing the failure.
   *
   * @return array|string
   */
  private function listOrFailure(array $result, bool $notFoundIsEmpty): array|string {
    if ($result['error'] !== NULL) {
      return $result['error'];
    }
    if ($result['status'] === 404 && $notFoundIsEmpty) {
      return [];
    }
    if ($result['status'] >= 400) {
      return sprintf('Codebase API error (%d).', $result['status']);
    }
    $list = json_decode((string) $result['body'], TRUE);
    if (!is_array($list) || !array_is_list($list)) {
      return 'Unexpected response from Codebase.';
    }
    // Both tickets and events arrive wrapped, one key deep.
    return array_map(fn($item) => is_array($item) && count($item) === 1 && is_array(reset($item)) ? reset($item) : $item, $list);
  }

  /**
   * The newest timestamp among items, or NULL.
   */
  private function latest(array $items, callable $field): ?int {
    $times = array_filter(array_map(function ($item) use ($field) {
      $value = is_array($item) ? $field($item) : NULL;
      return is_string($value) ? strtotime($value) : FALSE;
    }, $items));
    return $times ? max($times) : NULL;
  }

  /**
   * Removes the user from one project, verifying and if need be undoing it.
   */
  private function removeUser(string $permalink, int $myId): array {
    try {
      $before = $this->assignedIds($permalink);
    }
    catch (\Exception $e) {
      return $this->outcome($permalink, 'failed', 'Could not read the project users; nothing was changed. ' . $e->getMessage());
    }
    if (!in_array($myId, $before, TRUE)) {
      return $this->outcome($permalink, 'skipped', 'You are not in the project\'s list of users.');
    }
    $remaining = array_values(array_diff($before, [$myId]));
    if (!$remaining) {
      return $this->outcome($permalink, 'skipped', 'You are the only user assigned; not changed.');
    }

    $error = NULL;
    try {
      ($this->postXml)("/{$permalink}/assignments", $this->usersXml($remaining));
    }
    catch (\Exception $e) {
      $error = $e->getMessage();
    }

    // Whatever the response said, judge by what the project looks like now.
    $after = $this->assignedIdsOrNull($permalink);
    if ($after !== NULL && $this->sameIds($after, $remaining)) {
      error_log(sprintf('Unassigned user_id=%d from project=%s', $myId, $permalink));
      return $this->outcome($permalink, 'unassigned', 'You were removed from the project. ' . count($remaining) . ' other user(s) remain assigned.');
    }
    if ($after !== NULL && $this->sameIds($after, $before)) {
      return $this->outcome($permalink, 'failed', 'Codebase did not change the assignments. ' . ($error ?? 'No error was reported.'));
    }

    // Unexpected state, possibly with other users missing: put it back.
    return $this->restore($permalink, $before, $error);
  }

  private function restore(string $permalink, array $before, ?string $error): array {
    error_log(sprintf('Unexpected assignments after change in project=%s; restoring', $permalink));
    $restoreError = NULL;
    try {
      ($this->postXml)("/{$permalink}/assignments", $this->usersXml($before));
    }
    catch (\Exception $e) {
      $restoreError = $e->getMessage();
    }
    $now = $this->assignedIdsOrNull($permalink);
    if ($now !== NULL && $this->sameIds($now, $before)) {
      return $this->outcome($permalink, 'failed', 'The result was not what was intended, so the original users were restored. Nothing was changed. ' . ($error ?? ''));
    }

    error_log(sprintf('RESTORE FAILED for project=%s; original user ids: %s', $permalink, implode(',', $before)));
    return $this->outcome($permalink, 'failed', sprintf(
      'RESTORE FAILED. The project users may be wrong; an administrator must check them. The original user ids were: %s. %s',
      implode(', ', $before),
      $restoreError ?? ''
    ));
  }

  /**
   * @return int[]
   *   The ids of the users assigned to a project.
   *
   * @throws \Exception If the list cannot be read or is empty.
   */
  private function assignedIds(string $permalink): array {
    $users = ($this->get)("/{$permalink}/assignments");
    $ids = [];
    foreach ($users as $item) {
      $user = is_array($item) && isset($item['user']) && is_array($item['user']) ? $item['user'] : $item;
      if (is_array($user) && isset($user['id']) && is_int($user['id'])) {
        $ids[] = $user['id'];
      }
    }
    if (!$ids || !array_is_list($users)) {
      throw new \Exception('Unexpected list of users from Codebase.');
    }
    return $ids;
  }

  private function assignedIdsOrNull(string $permalink): ?array {
    try {
      return $this->assignedIds($permalink);
    }
    catch (\Exception) {
      return NULL;
    }
  }

  private function sameIds(array $a, array $b): bool {
    sort($a);
    sort($b);
    return $a === $b;
  }

  private function usersXml(array $ids): string {
    return '<users>' . implode('', array_map(fn(int $id) => "<user><id>$id</id></user>", $ids)) . '</users>';
  }

}
