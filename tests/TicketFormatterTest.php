<?php

namespace petertornstrand\Tests;

use PHPUnit\Framework\TestCase;
use petertornstrand\TicketFormatter;

class TicketFormatterTest extends TestCase {

  public function testCompactsAWrappedTicketAndDropsBulkyFields(): void {
    $compact = TicketFormatter::compact(['ticket' => [
      'ticket_id' => 12, 'summary' => 'Fix login', 'ticket_type' => 'Bug',
      'status' => ['name' => 'In Progress'], 'priority' => ['name' => 'High'],
      'assignee' => 'Peter', 'milestone' => ['name' => 'Rel 1'], 'deadline' => '2026-11-01',
      'updated_at' => '2026-10-01', 'description' => 'long text', 'tags' => 'a', 'reporter' => 'Anna',
    ]], 'acme');

    $this->assertSame([
      'project' => 'acme', 'id' => 12, 'summary' => 'Fix login', 'type' => 'Bug', 'status' => 'In Progress',
      'priority' => 'High', 'assignee' => 'Peter', 'milestone' => 'Rel 1', 'deadline' => '2026-11-01',
      'updated_at' => '2026-10-01',
    ], $compact);
  }

  public function testAcceptsBareTicketsAndAlternativeFieldShapes(): void {
    $compact = TicketFormatter::compact([
      'id' => 5, 'summary' => 'x', 'type' => 'Task', 'status' => 'Open',
      'assignee' => ['username' => 'peter'], 'priority' => ['title' => 'Low'],
    ]);
    $this->assertSame(['id' => 5, 'summary' => 'x', 'type' => 'Task', 'status' => 'Open', 'priority' => 'Low', 'assignee' => 'peter'], $compact);
  }

  public function testMissingAndEmptyFieldsAreLeftOut(): void {
    $compact = TicketFormatter::compact(['ticket' => ['ticket_id' => 1, 'summary' => 'Only this', 'status' => '', 'assignee' => NULL, 'priority' => ['id' => 3]]]);
    $this->assertSame(['id' => 1, 'summary' => 'Only this'], $compact);
  }

  public function testCompactListHandlesListsAndLeavesOtherShapesAlone(): void {
    $list = TicketFormatter::compactList([['ticket' => ['ticket_id' => 1, 'summary' => 'a']], 'odd', ['ticket' => ['ticket_id' => 2]]], 'p');
    $this->assertSame(['project' => 'p', 'id' => 1, 'summary' => 'a'], $list[0]);
    $this->assertSame('odd', $list[1]);
    $this->assertSame(['project' => 'p', 'id' => 2], $list[2]);

    $notAList = ['echo' => '/x'];
    $this->assertSame($notAList, TicketFormatter::compactList($notAList));
    $this->assertSame([], TicketFormatter::compactList([]));
  }

  public function testCompactsATicketShapedLikeTheRealApi(): void {
    $compact = TicketFormatter::compact(['ticket' => [
      'ticket_id' => 3902, 'summary' => 'Sak', 'ticket_type' => 'bug', 'reporter_id' => 1, 'assignee_id' => 2,
      'assignee' => 'peter', 'reporter' => 'anna',
      'category_id' => 30, 'category' => ['id' => 30, 'name' => 'Bug'],
      'priority_id' => 21, 'priority' => ['id' => 21, 'name' => 'High', 'colour' => '#f00', 'default' => FALSE, 'position' => 3],
      'status_id' => 10, 'status' => ['id' => 10, 'name' => 'In Progress', 'colour' => '#0f0', 'order' => 1, 'treat-as-closed' => FALSE],
      'type_id' => 1, 'type' => ['id' => 1, 'name' => 'Bug', 'icon' => 'bug'],
      'milestone_id' => 5, 'milestone' => ['id' => 5, 'identifier' => 'r', 'name' => 'Rel 2026-99', 'deadline' => '2026-11-01', 'parent_id' => NULL],
      'start_on' => NULL, 'deadline' => '2026-11-02', 'tags' => 'a', 'updated_at' => '2026-10-01T10:00:00Z', 'created_at' => '2026-09-01',
      'estimated_time' => NULL, 'project_id' => 9, 'total_time_spent' => 0,
    ]], 'ki-profile');

    $this->assertSame([
      'project' => 'ki-profile', 'id' => 3902, 'summary' => 'Sak', 'type' => 'bug', 'status' => 'In Progress', 'priority' => 'High',
      'assignee' => 'peter', 'milestone' => 'Rel 2026-99', 'deadline' => '2026-11-02', 'updated_at' => '2026-10-01T10:00:00Z',
    ], $compact);
  }

  public function testTicketTypeIsTakenFromTheStringFieldBeforeTheObject(): void {
    $this->assertSame('task', TicketFormatter::compact(['ticket_id' => 1, 'ticket_type' => 'task', 'type' => ['name' => 'Bug']])['type']);
    $this->assertSame('Bug', TicketFormatter::compact(['ticket_id' => 1, 'type' => ['name' => 'Bug']])['type']);
  }

}
