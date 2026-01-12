<?php
// Small partial to render actions for a channel message
if (!isset($m) || !is_array($m)) return;
$canDelete = ($m['user_id'] ?? 0) == ($current_user_id ?? 0) || (($current_user_role ?? '') === 'admin');
?>
<div style="display:inline-flex;gap:8px;align-items:center">
  <?php if ($canDelete): ?>
    <button class="btn secondary deleteMsgBtn" data-id="<?= (int)$m['id'] ?>">Delete</button>
  <?php endif; ?>
</div>