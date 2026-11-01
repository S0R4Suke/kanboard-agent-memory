<section class="am-card am-changes">
    <h2>メッセージの変更履歴<?= $entryId ? ' #'.(int) $entryId : '' ?></h2>
    <p>作業ログ・判断履歴の編集前後と削除前の内容です。古い変更から順に表示します。</p>
    <nav class="am-paging" aria-label="変更履歴の移動">
        <?= $this->url->link('タスクに戻る', 'TaskViewController', 'show', array('task_id' => $task['id'])) ?>
        <?php if ($entryId || $afterId): ?><?= $this->url->link('このタスクの変更履歴を最初から見る', 'MemoryController', 'changes', array('plugin' => 'AgentMemory', 'task_id' => $task['id'], 'project_id' => $task['project_id'])) ?><?php endif ?>
    </nav>
    <?php if (!$page['changes']): ?><p>変更履歴はありません。</p><?php endif ?>
    <?php foreach ($page['changes'] as $change): ?>
        <?php $fields = $change['before']['kind'] === 'work_log' ? array('title' => 'タイトル', 'body' => '作業内容・結果') : array('decision' => '判断内容', 'reason' => '判断理由', 'judge' => '判断者', 'decided_at' => '判断日時', 'options' => '検討した選択肢', 'tradeoffs' => 'トレードオフ', 'failure_conditions' => '失敗条件', 'action' => '記録種別', 'supersedes_id' => '参照元の記録ID'); ?>
        <article class="am-change" id="am-change-<?= $change['id'] ?>">
            <h3><?= $change['before']['kind'] === 'work_log' ? '作業ログ' : '判断履歴' ?> #<?= $change['message_id'] ?>：<?= $change['operation'] === 'delete' ? '削除' : '編集' ?></h3>
            <p class="am-caption"><time datetime="<?= gmdate('c', $change['created_at']) ?>"><?= date('Y-m-d H:i:s P', $change['created_at']) ?></time> ／ <?= $change['actor_type'] === 'ai' ? 'AI' : '人間' ?> ／ 記録者：<?= $this->text->e($change['recorder_name']) ?><?php if ($change['agent'] !== ''): ?> ／ <?= $this->text->e($change['agent']) ?><?php endif ?> ／ 版 <?= (int) $change['before']['revision'] ?> → <?= (int) $change['after']['revision'] ?></p>
            <div class="am-change-versions">
                <?php foreach (array('before' => '変更前', 'after' => '変更後') as $side => $label): ?>
                    <details open>
                        <summary><?= $label ?></summary>
                        <?php if ($side === 'after' && $change['operation'] === 'delete'): ?>
                            <p>削除済み。削除前の内容は変更前に保持しています。</p>
                        <?php else: ?>
                            <dl>
                                <?php foreach ($fields as $key => $name): ?>
                                    <?php if (isset($change[$side]['data'][$key])): ?>
                                        <dt><?= $name ?></dt><dd class="am-prose"><?php if ($key === 'decided_at'): ?><?= date('Y-m-d H:i:s P', strtotime($change[$side]['data'][$key])) ?> / <code><?= $this->text->e($change[$side]['data'][$key]) ?></code><?php elseif ($key === 'action'): ?><?= $this->text->e(array('decision' => '新しい判断', 'withdrawal' => '撤回', 'correction' => '訂正')[$change[$side]['data'][$key]] ?? $change[$side]['data'][$key]) ?><?php else: ?><?= $this->text->e($change[$side]['data'][$key]) ?><?php endif ?></dd>
                                    <?php endif ?>
                                <?php endforeach ?>
                            </dl>
                        <?php endif ?>
                    </details>
                <?php endforeach ?>
            </div>
        </article>
    <?php endforeach ?>
    <?php if ($page['has_more']): ?><nav class="am-paging" aria-label="変更履歴のページ"><?= $this->url->link('次の変更を見る', 'MemoryController', 'changes', array('plugin' => 'AgentMemory', 'task_id' => $task['id'], 'project_id' => $task['project_id'], 'entry_id' => $entryId, 'after_id' => $page['next_after_id'])) ?></nav><?php endif ?>
</section>
