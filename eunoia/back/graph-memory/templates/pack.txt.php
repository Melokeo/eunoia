<?php
// Minimal template (alternative approach).
// Not strictly required if using Render::pack() directly.
?>
[Memory v1]
Seeds: <?= $seeds ? implode('; ', $seeds) : '(none)' ?>

Facts:
<?php foreach ($nodes as $n): ?>
- <?= $n['type'] ?> <?= $n['title'] ?> (conf=<?= sprintf('%.2f',$n['confidence']??0) ?>)
<?php endforeach; ?>

Relations:
<?php foreach ($edges as $e): ?>
- <?= $e['src_id'] ?> --<?= $e['type'] ?>(<?= sprintf('%.2f',$e['weight']??0) ?>)--> <?= $e['dst_id'] ?>
<?php endforeach; ?>

Window <?= $windowDays ?>d  Nodes <?= count($nodes) ?>  Hop <?= $hop ?>

