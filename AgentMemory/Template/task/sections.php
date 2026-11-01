<?php
$editable = $this->user->hasProjectAccess('TaskModificationController', 'edit', $task['project_id']);
$sections = array('work_log' => '作業ログ', 'decision' => '判断履歴');
?>
        <nav class="am-audit-nav" aria-label="メッセージの変更履歴"><?= $this->url->link('変更履歴（編集・削除）', 'MemoryController', 'changes', array('plugin' => 'AgentMemory', 'task_id' => $task['id'], 'project_id' => $task['project_id'])) ?></nav>
        <?php foreach ($sections as $kind => $label): ?>
        <?php
        $before = $kind === 'work_log' ? $logBefore : $decisionBefore;
        $page = $store->messages((int) $task['id'], $kind, 0, 20, '', $before, true);
        $oldestId = $page['entries'] ? end($page['entries'])['id'] : 0;
        ?>
        <section class="am-card" id="am-<?= $kind ?>">
            <div class="am-card-heading"><h2><i class="fa <?= $kind === 'work_log' ? 'fa-clock-o' : 'fa-file-text-o' ?>" aria-hidden="true"></i> <?= $label ?></h2><span class="am-caption"><?= $kind === 'work_log' ? '人間・生成AIの記録' : '人間の判断と、その理由' ?></span></div>
            <div class="am-history-scroll" tabindex="0" role="region" aria-label="<?= $label ?>の記録">
            <?php if (!$page['entries']): ?>
                <p class="am-empty"><?= $kind === 'work_log' ? 'まだ作業ログはありません。調査結果や作業の区切りを記録しましょう。' : 'まだ判断の記録はありません。採用した案と理由を残しておけます。' ?></p>
            <?php else: ?>
            <ol class="am-timeline">
                <?php foreach (array_reverse($page['entries']) as $entry): ?>
                <?php $data = $entry['data']; ?>
                <li id="am-entry-<?= $entry['id'] ?>" class="<?= $entry['actor_type'] === 'human' ? 'am-human' : 'am-ai' ?>">
                    <?php $timestamp = $kind === 'decision' && !empty($data['decided_at']) ? strtotime($data['decided_at']) : $entry['created_at']; ?>
                    <time class="am-event-date" datetime="<?= gmdate('c', $timestamp) ?>"><span><?= $this->dt->date($timestamp) ?></span><span><?= $this->dt->time($timestamp) ?></span><span class="am-entry-id">#<?= $entry['id'] ?></span></time>
                    <div class="am-event">
                    <div class="am-event-heading">
                        <?php if ($kind === 'work_log'): ?><span class="am-event-icon" aria-hidden="true"><i class="fa fa-file-text-o"></i></span><?php endif ?>
                        <?php if ($kind === 'work_log' && !empty($data['title'])): ?><h3><?= $this->text->e($data['title']) ?></h3><?php endif ?>
                        <?php if ($kind === 'decision'): ?><span class="am-judge"><i class="fa fa-user-o" aria-hidden="true"></i> <?= $this->text->e($data['judge']) ?></span><?php endif ?>
                        <div class="am-actor"><span class="am-badge"><?= $entry['actor_type'] === 'ai' ? 'AI' : '人間' ?></span><span><?= $this->text->e($entry['agent'] !== '' ? $entry['agent'] : $entry['recorder_name']) ?></span></div>
                    </div>
                    <?php if ($kind === 'work_log'): ?>
                        <p class="am-prose"><?= $this->text->e($data['body']) ?></p>
                    <?php else: ?>
                        <details class="am-decision">
                            <summary><strong><?= $this->text->e($data['decision']) ?></strong><?php if (!empty($data['reason'])): ?><span class="am-reason"><?= $this->text->e($data['reason']) ?></span><?php endif ?><span class="am-expand">詳細を開く</span></summary>
                            <dl>
                                <?php foreach (array('judge' => '判断者', 'decided_at' => '判断日時', 'options' => '検討した選択肢', 'tradeoffs' => 'トレードオフ', 'failure_conditions' => '失敗条件') + (($data['action'] ?? '') === 'withdrawal' ? array('supersedes_id' => '撤回元の記録ID') : array()) as $key => $name): ?>
                                    <?php if (isset($data[$key])): ?><dt><?= $name ?></dt><dd class="am-prose"><?= $this->text->e($data[$key]) ?></dd><?php endif ?>
                                <?php endforeach ?>
                            </dl>
                        </details>
                    <?php endif ?>
                    <span class="am-recorder">記録者：<?= $this->text->e($entry['recorder_name']) ?></span>
                    <?php if ($entry['revision'] > 1): ?><span class="am-recorder">編集済み：<?= $this->text->e($entry['editor_name']) ?> / <?= $this->dt->datetime($entry['updated_at']) ?></span><?php endif ?>
                    <?php if ($kind === 'decision'): ?><span class="am-recorder">記録日時：<?= $this->dt->datetime($entry['created_at']) ?><?= empty($data['decided_at']) ? '（判断日時は未入力）' : '' ?></span><?php endif ?>
                    <?php if ($editable): ?><div class="am-entry-actions"><?= $this->url->link('編集', 'MemoryController', 'edit', array('plugin' => 'AgentMemory', 'task_id' => $task['id'], 'project_id' => $task['project_id'], 'entry_id' => $entry['id'])) ?> <?= $this->url->link('削除', 'MemoryController', 'confirmDelete', array('plugin' => 'AgentMemory', 'task_id' => $task['id'], 'project_id' => $task['project_id'], 'entry_id' => $entry['id'])) ?></div><?php endif ?>
                    <?php if ($entry['revision'] > 1): ?><div class="am-audit-link"><?= $this->url->link('変更履歴を見る', 'MemoryController', 'changes', array('plugin' => 'AgentMemory', 'task_id' => $task['id'], 'project_id' => $task['project_id'], 'entry_id' => $entry['id'])) ?></div><?php endif ?>
                    </div>
                </li>
                <?php endforeach ?>
            </ol>
            <?php endif ?>
            </div>
            <nav class="am-paging" aria-label="<?= $label ?>のページ">
                <?php if ($page['has_more']): ?><?= $this->url->link('以前の記録を見る', 'TaskViewController', 'show', array('task_id' => $task['id'], 'am_'.$kind.'_before' => $oldestId)) ?><?php endif ?>
                <?php if ($before): ?><?= $this->url->link('最新の記録に戻る', 'TaskViewController', 'show', array('task_id' => $task['id'])) ?><?php endif ?>
            </nav>
            <?php if ($editable): ?>
                <details class="am-add"><summary><?= $label ?>を追加</summary>
                <?= $this->render('AgentMemory:task/form', array('task' => $task, 'kind' => $kind, 'values' => array())) ?>
                </details>
            <?php endif ?>
        </section>
        <?php endforeach ?>
