<?php
declare(strict_types=1);

/** Existing release/workspace tables only. Callers own account, Agent and Chat records. */
function release_agent_fixture_put_v1(PDO $pdo,string $table,array $row): void
{
    $columns=array_keys($row);
    $pdo->prepare('INSERT INTO '.$table.' ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($row));
}

function release_agent_fixture_v1(PDO $pdo,string $mode=''): void
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$id=$mysql?'INTEGER PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $schemas=[
        'artist_workspaces_v181'=>'id INTEGER PRIMARY KEY,artist_user_id INTEGER NOT NULL,workspace_name VARCHAR(190) NOT NULL',
        'artist_team_members'=>'artist_user_id INTEGER NOT NULL,member_user_id INTEGER NOT NULL,team_role VARCHAR(30) NOT NULL,PRIMARY KEY(artist_user_id,member_user_id)',
        'user_plugin_installations'=>'user_id INTEGER NOT NULL,plugin_key VARCHAR(80) NOT NULL,status VARCHAR(20) NOT NULL,settings_json TEXT,PRIMARY KEY(user_id,plugin_key)',
        'release_plans'=>"id $id,workspace_id INTEGER,owner_user_id INTEGER NOT NULL,created_by_user_id INTEGER,title VARCHAR(190) NOT NULL,release_type VARCHAR(40) NOT NULL DEFAULT 'single',status VARCHAR(30) NOT NULL DEFAULT 'planning',priority VARCHAR(20) NOT NULL DEFAULT 'normal',target_date DATETIME,agent_goal TEXT,notes TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        'release_items'=>"id $id,workspace_id INTEGER,release_id INTEGER NOT NULL,item_type VARCHAR(40) NOT NULL DEFAULT 'task',title VARCHAR(190) NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'todo',due_at DATETIME,completed_at DATETIME,assigned_user_id INTEGER,track_id INTEGER,show_id INTEGER,instructions TEXT,agent_notes TEXT,sort_order INTEGER NOT NULL DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP",
    ];
    foreach($schemas as $table=>$schema)$pdo->exec('CREATE TABLE IF NOT EXISTS '.$table.' ('.$schema.')');
    if(!(int)$pdo->query('SELECT COUNT(*) FROM artist_workspaces_v181 WHERE id=11')->fetchColumn()){
        foreach([[11,1,'Fixture Music Workspace'],[21,2,'Foreign Music Workspace']] as [$wid,$uid,$name])release_agent_fixture_put_v1($pdo,'artist_workspaces_v181',['id'=>$wid,'artist_user_id'=>$uid,'workspace_name'=>$name]);
        foreach([1,2] as $uid){$q=$pdo->prepare("SELECT COUNT(*) FROM user_plugin_installations WHERE user_id=? AND plugin_key='music_workspace'");$q->execute([$uid]);$installed=(int)$q->fetchColumn();$q->closeCursor();if(!$installed)release_agent_fixture_put_v1($pdo,'user_plugin_installations',['user_id'=>$uid,'plugin_key'=>'music_workspace','status'=>'enabled','settings_json'=>'{}']);}
        foreach([
            [101,11,1,'Fixture Autumn Single','single','planning','normal','2030-01-15 12:00:00'],
            [102,11,1,'Fixture Archive Launch','album','released','normal','2020-01-15 12:00:00'],
            [103,11,1,'Fixture Paused Campaign','campaign','paused','high','2030-02-01 12:00:00'],
            [201,21,2,'SECRET Foreign Release','single','planning','urgent','2030-01-01 12:00:00'],
        ] as [$rid,$wid,$uid,$title,$type,$status,$priority,$date])release_agent_fixture_put_v1($pdo,'release_plans',['id'=>$rid,'workspace_id'=>$wid,'owner_user_id'=>$uid,'created_by_user_id'=>$uid,'title'=>$title,'release_type'=>$type,'status'=>$status,'priority'=>$priority,'target_date'=>$date,'agent_goal'=>'Fixture internal release planning goal','notes'=>'Fixture release planning notes']);
        foreach([
            [1001,11,101,'Fixture artwork review','todo','2030-01-10 12:00:00',null],
            [1002,11,101,'Fixture master approved','complete','2030-01-09 12:00:00','2026-01-01 12:00:00'],
            [1003,11,101,'Fixture distribution checklist','blocked','2000-01-01 12:00:00',null],
            [1004,11,102,'Fixture archive task','complete','2020-01-10 12:00:00','2020-01-11 12:00:00'],
            [2001,21,201,'SECRET Foreign Task','todo','2030-01-01 12:00:00',null],
            [2002,21,101,'SECRET Inconsistent Workspace Task','complete','2030-01-01 12:00:00','2026-01-01 12:00:00'],
        ] as [$iid,$wid,$rid,$title,$status,$due,$done])release_agent_fixture_put_v1($pdo,'release_items',['id'=>$iid,'workspace_id'=>$wid,'release_id'=>$rid,'item_type'=>'task','title'=>$title,'status'=>$status,'due_at'=>$due,'completed_at'=>$done,'instructions'=>'Fixture task instructions','agent_notes'=>'SECRET /private/provider/token do not project']);
    }
    $mode=$mode!==''?$mode:(string)($_SERVER['HTTP_X_FIXTURE_RELEASE_MODE']??'');
    if($mode==='empty'){foreach(['release_items','release_plans'] as $table)$pdo->exec('DELETE FROM '.$table.' WHERE workspace_id=11');}
    elseif($mode==='multi_workspace')$pdo->exec("INSERT INTO artist_team_members(artist_user_id,member_user_id,team_role) VALUES(2,1,'manager')");
    elseif($mode==='disabled')$pdo->exec("UPDATE user_plugin_installations SET status='disabled' WHERE user_id=1 AND plugin_key='music_workspace'");
    elseif($mode==='missing_schema')$pdo->exec('DROP TABLE release_items');
}
