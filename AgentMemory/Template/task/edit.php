<section class="am-card">
    <h2><?= $values['kind'] === 'work_log' ? '作業ログを編集' : '判断履歴を編集' ?></h2>
    <p>メッセージ #<?= (int) $values['entry_id'] ?> の内容を更新します。</p>
    <?= $this->render('AgentMemory:task/form', array('task' => $task, 'kind' => $values['kind'], 'values' => $values)) ?>
    <?= $this->url->link('タスクに戻る', 'TaskViewController', 'show', array('task_id' => $task['id'])) ?>
</section>
