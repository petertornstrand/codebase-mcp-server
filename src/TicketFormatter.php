<?php

namespace petertornstrand;

/**
 * Compacts Codebase ticket JSON into short summaries.
 *
 * Full tickets carry many fields clients rarely need; summaries keep answers
 * fast to read. get_ticket still returns everything.
 */
final class TicketFormatter {

  /**
   * Compacts a list of tickets; anything that is not a list is left alone.
   */
  public static function compactList(array $tickets, ?string $project = NULL): array {
    if (!array_is_list($tickets)) {
      return $tickets;
    }
    return array_map(fn($item) => is_array($item) ? self::compact($item, $project) : $item, $tickets);
  }

  /**
   * @param array $item
   *   A ticket, either bare or wrapped as {"ticket": {...}}.
   */
  public static function compact(array $item, ?string $project = NULL): array {
    $ticket = isset($item['ticket']) && is_array($item['ticket']) ? $item['ticket'] : $item;

    $summary = [
      'project' => $project,
      'id' => $ticket['ticket_id'] ?? $ticket['id'] ?? NULL,
      'summary' => $ticket['summary'] ?? NULL,
      'type' => self::label($ticket['ticket_type'] ?? $ticket['type'] ?? NULL),
      'status' => self::label($ticket['status'] ?? NULL),
      'priority' => self::label($ticket['priority'] ?? NULL),
      'assignee' => self::label($ticket['assignee'] ?? NULL),
      'milestone' => self::label($ticket['milestone'] ?? NULL),
      'deadline' => $ticket['deadline'] ?? NULL,
      'updated_at' => $ticket['updated_at'] ?? NULL,
    ];
    return array_filter($summary, fn($v) => $v !== NULL && $v !== '');
  }

  /**
   * A readable name from a string or a nested object.
   */
  private static function label(mixed $value): ?string {
    if (is_array($value)) {
      $value = $value['name'] ?? $value['username'] ?? $value['title'] ?? NULL;
    }
    return is_scalar($value) ? (string) $value : NULL;
  }

}
