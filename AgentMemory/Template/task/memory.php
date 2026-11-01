<section class="am-memory am-native-memory" aria-label="作業の経緯">
    <?= $this->render('AgentMemory:task/sections', array('task' => $task, 'project' => $project, 'store' => $store, 'logBefore' => $logBefore, 'decisionBefore' => $decisionBefore)) ?>
</section>
