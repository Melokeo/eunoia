<?php
/**
 * Message timestamping module - adds time-group stamps to messages.
 */

declare(strict_types=1);

/**
 * Format timestamp as [10.07Tue13:28]
 */
function stamp_fmt(DateTime $dt): string {
  return '[' . $dt->format('m.dD H:i') . '] ';
}

/**
 * Determine if new time group starts (day change or gap >= threshold)
 */
function need_new_group(?DateTime $prev, DateTime $cur, int $thMin=5): bool {
  if ($prev === null) return true;
  if ($prev->format('Y-m-d') !== $cur->format('Y-m-d')) return true;
  $diff = abs($prev->getTimestamp() - $cur->getTimestamp());
  return $diff >= $thMin * 60;
}

/**
 * Apply time-group stamps to first message of each group.
 * 
 * MAIN ENTRY POINT for timestamping messages.
 * 
 * @param array &$msgs Messages array (oldest→newest), modified in-place.
 *                     Each message should have '_ts' field (ISO datetime string).
 *                     Stamps are prepended to 'content' field.
 * @param int $thMin   Threshold in minutes. New group starts if gap >= threshold
 *                     or day changes. Default: 5 minutes.
 * @return void        Modifies $msgs in-place, no return value.
 */
function apply_group_stamps(array &$msgs, int $thMin=5): void {
  $lastStamped = null;  // track last stamped, not last msg
  for ($i=0; $i<count($msgs); $i++) {
    $tsStr = $msgs[$i]['_ts'] ?? null;
    if (!$tsStr) continue;
    try { $cur = new DateTime($tsStr); } catch (Throwable $e) { $cur = null; }
    $need = $cur ? need_new_group($lastStamped, $cur, $thMin) : false;
    if ($need) {
      $msgs[$i]['content'] = stamp_fmt($cur) . $msgs[$i]['content'];
      $lastStamped = $cur;  // update only when stamped
    }
  }
}