<?php
require __DIR__.'/../AgentMemory/Schema/Sqlite.php';
require __DIR__.'/../AgentMemory/Model/Store.php';
use Kanboard\Plugin\AgentMemory\Model\Store;
$p = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$p->exec('PRAGMA foreign_keys=ON');
$p->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY,project_id INTEGER,title TEXT)');
$p->exec("INSERT INTO tasks VALUES (1,1,'Legacy')");
\Kanboard\Plugin\AgentMemory\Schema\version_1($p);
\Kanboard\Plugin\AgentMemory\Schema\version_2($p);
$rows = array(
    array('work_log', array('body'=>'original')),
    array('work_log', array('body'=>'second','action'=>'correction','supersedes_id'=>1)),
    array('work_log', array('body'=>'third','action'=>'correction','supersedes_id'=>1)),
    array('work_log', array('body'=>'fourth','action'=>'correction','supersedes_id'=>2)),
    array('decision', array('decision'=>'before','judge'=>'user','action'=>'decision')),
    array('decision', array('decision'=>'after','judge'=>'user','action'=>'correction','supersedes_id'=>5)),
    array('decision', array('decision'=>'withdrawn','judge'=>'user','action'=>'withdrawal','supersedes_id'=>5)),
);
foreach ($rows as $i => $row) {
    list($kind,$data)=$row; ksort($data);
    $statement=$p->prepare('INSERT INTO agent_memory_entries (task_id,original_task_id,project_id,task_title,kind,created_at,recorder_id,recorder_name,actor_type,agent,request_id,fingerprint,payload) VALUES (1,1,1,?,?,?,?,?,?,?,?,?,?)');
    $statement->execute(array('Legacy',$kind,1000+$i,1,'user','human','legacy','legacy'.$i,hash('sha256',json_encode(array($kind,$data,1,'human','legacy',null),JSON_THROW_ON_ERROR)),json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)));
}
$original=$p->query('SELECT id,payload FROM agent_memory_entries ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
\Kanboard\Plugin\AgentMemory\Schema\version_3($p);
$s=new Store($p); $checks=0;
function verify($value,$label) {global $checks; if (!$value) throw new RuntimeException($label); $checks++;}
verify($p->query('SELECT id,payload FROM agent_memory_entries ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$original,'migration keeps every original payload');
verify(count($s->messages(1)['entries'])===3,'corrections collapse; withdrawal stays separate');
verify($s->message(1,1)['data']['body']==='fourth' && $s->message(1,1)['revision']===4,'latest of repeated and chained corrections wins');
verify($s->message(1,4)['id']===1,'old correction ID resolves stable root');
verify($s->message(1,5)['data']['decision']==='after','decision migration');
$task=array('id'=>1,'project_id'=>1,'title'=>'Legacy'); $actor=array('id'=>1,'name'=>'user','type'=>'human','agent'=>'legacy');
$edited=$s->change($task,1,'edit',array('body'=>'latest edit'),4,'new-edit',$actor);
$retry=$s->append($task,'work_log',$rows[2][1],'legacy2',$actor);
verify($retry['id']===3 && $s->message(1,1)['data']['body']==='latest edit','old append retry does not overwrite current edit');
$p->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON agent_memory_changes BEGIN SELECT RAISE(ABORT,'test failure'); END");
$before=$s->message(1,5);
try {$s->change($task,5,'edit',array('decision'=>'will roll back','judge'=>'user'),2,'failed',$actor); throw new LogicException('No injected failure');} catch (PDOException $e) {}
verify($s->message(1,5)===$before,'audit failure rolls back visible edit');
$p->exec('DROP TRIGGER fail_audit');
$s->change($task,4,'delete',null,5,'delete-root',$actor);
verify($s->message(1,1)===null && $s->message(1,2)===null,'deleting a legacy alias hides entire message');
verify(count($s->entries(1)['entries'])===7,'edit/delete do not add visible-source records');
echo "PASS: $checks legacy migration and rollback checks\n";
