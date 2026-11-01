<section class="am-card">
    <h2>メッセージを削除</h2>
    <?php if ($error !== ''): ?><p role="alert"><?= $this->text->e($error) ?></p><?php endif ?>
    <p>このメッセージを削除しますか？</p>
    <blockquote class="am-prose"><?= $this->text->e($entry['data']['body'] ?? $entry['data']['decision']) ?></blockquote>
    <form method="post" class="am-delete-form" action="<?= $this->url->href('MemoryController', 'delete', array('plugin' => 'AgentMemory', 'task_id' => $task['id'], 'project_id' => $task['project_id'])) ?>">
        <?= $this->form->csrf() ?>
        <input type="hidden" name="entry_id" value="<?= $entry['id'] ?>">
        <input type="hidden" name="expected_revision" value="<?= $entry['revision'] ?>">
        <input type="hidden" name="request_id" value="<?= bin2hex(random_bytes(16)) ?>">
        <button type="submit" class="btn btn-red">削除する</button>
        <?= $this->url->link('キャンセル', 'TaskViewController', 'show', array('task_id' => $task['id'])) ?>
    </form>
</section>
