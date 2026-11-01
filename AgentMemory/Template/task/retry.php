<section class="am-card">
    <h2>履歴を保存できませんでした</h2>
    <p role="alert"><?= $this->text->e($error) ?></p>
    <?= $this->render('AgentMemory:task/form', array('task' => $task, 'kind' => $values['kind'], 'values' => $values)) ?>
</section>
