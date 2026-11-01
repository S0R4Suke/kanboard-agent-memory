<?php
$prefix = 'am-'.$kind.'-';
$requestId = $values['request_id'] ?? bin2hex(random_bytes(16));
$fields = $kind === 'work_log' ? array('title' => 'タイトル（任意）', 'body' => '作業内容・結果') : array('decision' => '判断内容', 'reason' => '判断理由（任意）', 'judge' => '判断者', 'options' => '検討した選択肢（任意）', 'tradeoffs' => 'トレードオフ（任意）', 'failure_conditions' => '失敗条件（任意）');
$timezone = $values['decided_at_timezone'] ?? date_default_timezone_get();
$decisionTime = $values['decided_at_local'] ?? (empty($values['decided_at']) ? '' : date('Y-m-d\TH:i:s', strtotime($values['decided_at'])));
$timeChoices = array();
if ($kind === 'decision' && $decisionTime !== '') {
    try { $timeChoices = \Kanboard\Plugin\AgentMemory\Model\DecisionTime::candidates($decisionTime, $timezone); }
    catch (\InvalidArgumentException $error) { /* Invalid input is retained for correction. */ }
}
?>
<form method="post" action="<?= $this->url->href('MemoryController', 'save', array('plugin' => 'AgentMemory', 'task_id' => $task['id'], 'project_id' => $task['project_id'])) ?>" class="am-form">
    <?= $this->form->csrf() ?>
    <input type="hidden" name="kind" value="<?= $kind ?>">
    <input type="hidden" name="request_id" value="<?= $this->text->e($requestId) ?>">
    <?php if (isset($values['entry_id'])): ?>
        <input type="hidden" name="entry_id" value="<?= (int) $values['entry_id'] ?>">
        <input type="hidden" name="expected_revision" value="<?= (int) $values['expected_revision'] ?>">
    <?php endif ?>
    <?php foreach ($fields as $field => $label): ?>
        <label for="<?= $prefix.$field ?>"><?= $label ?></label>
        <?php $value = $values[$field] ?? ($field === 'judge' ? $this->user->getFullname() : ''); ?>
        <?php if (in_array($field, array('title', 'judge'))): ?>
            <input id="<?= $prefix.$field ?>" name="<?= $field ?>" value="<?= $this->text->e($value) ?>" maxlength="200" <?= $field === 'judge' ? 'required' : '' ?>>
        <?php else: ?>
            <textarea id="<?= $prefix.$field ?>" name="<?= $field ?>" rows="3" maxlength="65536" <?= in_array($field, array('body', 'decision')) ? 'required' : '' ?>><?= $this->text->e($value) ?></textarea>
        <?php endif ?>
    <?php endforeach ?>
    <?php if ($kind === 'decision'): ?>
        <label for="<?= $prefix ?>decided_at_local">判断日時（任意）</label>
        <input type="datetime-local" step="1" id="<?= $prefix ?>decided_at_local" name="decided_at_local" value="<?= $this->text->e($decisionTime) ?>" aria-describedby="<?= $prefix ?>time-help">
        <input type="hidden" name="decided_at_timezone" value="<?= $this->text->e($timezone) ?>">
        <p class="am-caption" id="<?= $prefix ?>time-help">タイムゾーン：<?= $this->text->e($timezone) ?>。判断した日時が不明なら空欄にします。記録日時は別に保存されます。</p>
        <?php if (count($timeChoices) > 1): ?>
            <label for="<?= $prefix ?>decided_at_choice">UTCオフセット（夏時間の切替で重複する日時）</label>
            <select id="<?= $prefix ?>decided_at_choice" name="decided_at_choice">
                <option value="">選択してください</option>
                <?php foreach ($timeChoices as $utc => $label): ?><option value="<?= $this->text->e($utc) ?>" <?= ($values['decided_at_choice'] ?? $values['decided_at'] ?? '') === $utc ? 'selected' : '' ?>><?= $this->text->e($label) ?></option><?php endforeach ?>
            </select>
        <?php endif ?>
    <?php endif ?>
    <?php if ($kind === 'decision' && !isset($values['entry_id'])): ?>
        <label for="<?= $prefix ?>action">記録種別</label>
        <select id="<?= $prefix ?>action" name="action"><?php foreach (array('decision' => '新しい判断', 'withdrawal' => '撤回') as $key => $name): ?><option value="<?= $key ?>" <?= ($values['action'] ?? 'decision') === $key ? 'selected' : '' ?>><?= $name ?></option><?php endforeach ?></select>
        <label for="<?= $prefix ?>supersedes_id">撤回元の記録ID（撤回時は必須）</label>
        <input type="number" min="1" id="<?= $prefix ?>supersedes_id" name="supersedes_id" value="<?= $this->text->e($values['supersedes_id'] ?? '') ?>">
    <?php endif ?>
    <p class="am-caption"><?= isset($values['entry_id']) ? '同じメッセージの内容を更新します。' : '保存したメッセージは、あとから編集・削除できます。' ?></p>
    <button type="submit" class="btn btn-blue"><?= isset($values['entry_id']) ? '更新する' : '記録を追加' ?></button>
</form>
