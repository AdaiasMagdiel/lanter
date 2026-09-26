<?php
interface Lanter_DriverInterface{public function connect(array$connection):void;public function listTables():array;public function describeTable(string$table):array;public function fetchRows(string$table,int$limit,int$offset):array;public function countRows(string$table):int;public function insertRow(string$table,array$data):void;public function updateRow(string$table,string$primaryKey,mixed$primaryValue,array$data):void;public function deleteRow(string$table,string$primaryKey,mixed$primaryValue):void;public function runQuery(string$sql):array;}class Lanter_SqliteDriver implements Lanter_DriverInterface{private PDO$pdo;public function connect(array$connection):void{$path=$connection['path']??$connection['database']??':memory:';$this->pdo=new PDO('sqlite:'.$path);$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);}public function listTables():array{$stmt=$this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");return array_column($stmt->fetchAll(),'name');}public function describeTable(string$table):array{$stmt=$this->pdo->prepare('PRAGMA table_info('.$this->quoteIdentifier($table).')');$stmt->execute();$columns=[];foreach($stmt->fetchAll()as$row){$columns[]=['name'=>$row['name'],'type'=>$row['type'],'nullable'=>$row['notnull']===0,'default'=>$row['dflt_value'],'key'=>$row['pk']>0?'PRI':'',];}return$columns;}public function fetchRows(string$table,int$limit,int$offset):array{$sql=sprintf('SELECT * FROM %s LIMIT %d OFFSET %d',$this->quoteIdentifier($table),$limit,$offset);return$this->pdo->query($sql)->fetchAll();}public function countRows(string$table):int{$stmt=$this->pdo->query('SELECT COUNT(*) AS total FROM '.$this->quoteIdentifier($table));return(int)$stmt->fetch()['total'];}public function insertRow(string$table,array$data):void{$columns=array_keys($data);$placeholders=array_map(static fn(string$column):string=>':'.$column,$columns);$sql=sprintf('INSERT INTO %s (%s) VALUES (%s)',$this->quoteIdentifier($table),implode(', ',array_map($this->quoteIdentifier(...),$columns)),implode(', ',$placeholders));$stmt=$this->pdo->prepare($sql);$stmt->execute($data);}public function updateRow(string$table,string$primaryKey,mixed$primaryValue,array$data):void{$assignments=implode(', ',array_map(fn(string$column):string=>$this->quoteIdentifier($column).' = :'.$column,array_keys($data)));$sql=sprintf('UPDATE %s SET %s WHERE %s = :__pk',$this->quoteIdentifier($table),$assignments,$this->quoteIdentifier($primaryKey));$stmt=$this->pdo->prepare($sql);$stmt->execute([...$data,'__pk'=>$primaryValue]);}public function deleteRow(string$table,string$primaryKey,mixed$primaryValue):void{$sql=sprintf('DELETE FROM %s WHERE %s = :__pk',$this->quoteIdentifier($table),$this->quoteIdentifier($primaryKey));$stmt=$this->pdo->prepare($sql);$stmt->execute(['__pk'=>$primaryValue]);}public function runQuery(string$sql):array{$stmt=$this->pdo->query($sql);if($stmt===false){return['columns'=>[],'rows'=>[],'affected'=>0];}if($stmt->columnCount()===0){return['columns'=>[],'rows'=>[],'affected'=>$stmt->rowCount()];}$rows=$stmt->fetchAll();$columns=$rows===[]?[]:array_keys($rows[0]);return['columns'=>$columns,'rows'=>$rows,'affected'=>count($rows)];}private function quoteIdentifier(string$identifier):string{return'"'.str_replace('"','""',$identifier).'"';}}class Lanter_MysqlDriver implements Lanter_DriverInterface{private PDO$pdo;private string$database;public function connect(array$connection):void{$host=$connection['host']??'127.0.0.1';$port=$connection['port']??3306;$this->database=$connection['database']??'';$dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$host,$port,$this->database);$this->pdo=new PDO($dsn,$connection['user']??'',$connection['password']??'');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);}public function listTables():array{$stmt=$this->pdo->query('SHOW TABLES');return array_column($stmt->fetchAll(PDO::FETCH_NUM),0);}public function describeTable(string$table):array{$stmt=$this->pdo->prepare('DESCRIBE '.$this->quoteIdentifier($table));$stmt->execute();$columns=[];foreach($stmt->fetchAll()as$row){$columns[]=['name'=>$row['Field'],'type'=>$row['Type'],'nullable'=>$row['Null']==='YES','default'=>$row['Default'],'key'=>$row['Key'],];}return$columns;}public function fetchRows(string$table,int$limit,int$offset):array{$sql=sprintf('SELECT * FROM %s LIMIT %d OFFSET %d',$this->quoteIdentifier($table),$limit,$offset);return$this->pdo->query($sql)->fetchAll();}public function countRows(string$table):int{$stmt=$this->pdo->query('SELECT COUNT(*) AS total FROM '.$this->quoteIdentifier($table));return(int)$stmt->fetch()['total'];}public function insertRow(string$table,array$data):void{$columns=array_keys($data);$placeholders=array_map(static fn(string$column):string=>':'.$column,$columns);$sql=sprintf('INSERT INTO %s (%s) VALUES (%s)',$this->quoteIdentifier($table),implode(', ',array_map($this->quoteIdentifier(...),$columns)),implode(', ',$placeholders));$stmt=$this->pdo->prepare($sql);$stmt->execute($data);}public function updateRow(string$table,string$primaryKey,mixed$primaryValue,array$data):void{$assignments=implode(', ',array_map(fn(string$column):string=>$this->quoteIdentifier($column).' = :'.$column,array_keys($data)));$sql=sprintf('UPDATE %s SET %s WHERE %s = :__pk',$this->quoteIdentifier($table),$assignments,$this->quoteIdentifier($primaryKey));$stmt=$this->pdo->prepare($sql);$stmt->execute([...$data,'__pk'=>$primaryValue]);}public function deleteRow(string$table,string$primaryKey,mixed$primaryValue):void{$sql=sprintf('DELETE FROM %s WHERE %s = :__pk',$this->quoteIdentifier($table),$this->quoteIdentifier($primaryKey));$stmt=$this->pdo->prepare($sql);$stmt->execute(['__pk'=>$primaryValue]);}public function runQuery(string$sql):array{$stmt=$this->pdo->query($sql);if($stmt===false){return['columns'=>[],'rows'=>[],'affected'=>0];}if($stmt->columnCount()===0){return['columns'=>[],'rows'=>[],'affected'=>$stmt->rowCount()];}$rows=$stmt->fetchAll();$columns=$rows===[]?[]:array_keys($rows[0]);return['columns'=>$columns,'rows'=>$rows,'affected'=>count($rows)];}private function quoteIdentifier(string$identifier):string{return'`'.str_replace('`','``',$identifier).'`';}}final class Lanter_Config{private array$data;private function __construct(array$data){$this->data=$data;}public static function resolve():self{$defaults=['auth'=>['mode'=>'login','delegate'=>null,],'connections'=>[],'allow_ui_connections'=>true,'data_file'=>__DIR__.'/lanter_data.sqlite',];$external=defined('LANTER_CONFIG')?LANTER_CONFIG:[];return new self(array_replace_recursive($defaults,$external));}public function authMode():string{return$this->data['auth']['mode'];}public function authDelegate():?callable{return$this->data['auth']['delegate'];}public function fixedConnections():array{return$this->data['connections'];}public function allowUiConnections():bool{return$this->data['allow_ui_connections'];}public function dataFile():string{return$this->data['data_file'];}}final class Lanter_Auth{public function __construct(private Lanter_Config$config){}public function resolveConnection():?array{return match($this->config->authMode()){'none'=>$this->resolveNoneMode(),'delegate'=>$this->resolveDelegateMode(),default=>$this->resolveLoginMode(),};}public function logout():void{unset($_SESSION['lanter_connection']);}private function resolveNoneMode():?array{if(isset($_SESSION['lanter_connection'])){return$_SESSION['lanter_connection'];}$connections=$this->config->fixedConnections();if($connections===[]){return null;}if(isset($_GET['conn'])&&isset($connections[$_GET['conn']])){$_SESSION['lanter_connection']=$connections[$_GET['conn']];return$_SESSION['lanter_connection'];}$_SESSION['lanter_connection']=reset($connections);return$_SESSION['lanter_connection'];}private function resolveDelegateMode():?array{$delegate=$this->config->authDelegate();if($delegate===null){return null;}$connection=$delegate();return is_array($connection)?$connection:null;}private function resolveLoginMode():?array{return$_SESSION['lanter_connection']??null;}public function login(array$connection):void{$_SESSION['lanter_connection']=$connection;}}function lanter_render_layout(string$title,string$content,array$tables=[],?string$activeTable=null):void{?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?=htmlspecialchars($title)?> · Lanter</title>
<style><?=lanter_css()?></style>
</head>
<body>
<div class="lanter-shell">
<aside class="lanter-sidebar">
<div class="lanter-brand">
<span class="lanter-brand-icon">&#128161;</span>
<span class="lanter-brand-name">Lanter</span>
</div>
<nav class="lanter-table-list">
<?php foreach($tables as$table):?>
<a class="lanter-table-link<?=$table===$activeTable?' active':''?>"
   href="?action=dashboard&amp;table=<?=urlencode($table)?>">
    <?=htmlspecialchars($table)?>
</a>
<?php endforeach;?>
</nav>
<a class="lanter-logout" href="?action=logout">Sair</a>
</aside>
<main class="lanter-main">
<?php if($activeTable!==null):?>
<nav class="lanter-tabs">
<a class="lanter-tab-link" href="?action=dashboard&amp;table=<?=urlencode($activeTable)?>">Dados</a>
<a class="lanter-tab-link" href="?action=table_structure&amp;table=<?=urlencode($activeTable)?>">Estrutura</a>
</nav>
<?php endif;?>
<?=$content?>
</main>
</div>
</body>
</html>
<?php
}function lanter_render_bare(string$title,string$content):void{?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?=htmlspecialchars($title)?> · Lanter</title>
<style><?=lanter_css()?></style>
</head>
<body>
<?=$content?>
</body>
</html>
<?php
}function lanter_action_login(Lanter_Config$config,Lanter_Auth$auth,?array$connection):void{$error=null;if($_SERVER['REQUEST_METHOD']==='POST'){$driver=$_POST['driver']??'sqlite';$candidate=$driver==='sqlite'?['driver'=>'sqlite','path'=>$_POST['path']??'']:['driver'=>$driver,'host'=>$_POST['host']??'127.0.0.1','port'=>(int)($_POST['port']??3306),'database'=>$_POST['database']??'','user'=>$_POST['user']??'','password'=>$_POST['password']??'',];try{lanter_make_driver($candidate);$auth->login($candidate);lanter_redirect('dashboard');return;}catch(Throwable$e){$error='Não foi possível conectar: '.$e->getMessage();}}$errorHtml=$error!==null?'<p style="color:#f43f5e">'.htmlspecialchars($error).'</p>':'';lanter_render_bare('Entrar',<<<HTML
        <form class="lanter-login" method="post" action="?action=login">
            <h1>Lanter</h1>
            {$errorHtml}
            <div class="lanter-field">
                <label>Driver</label>
                <select name="driver">
                    <option value="sqlite">SQLite</option>
                    <option value="mysql">MySQL / MariaDB</option>
                </select>
            </div>
            <div class="lanter-field">
                <label>Caminho do arquivo (SQLite) ou host (MySQL)</label>
                <input type="text" name="path" placeholder="/caminho/para/banco.sqlite">
                <input type="text" name="host" placeholder="127.0.0.1">
            </div>
            <div class="lanter-field">
                <label>Porta</label>
                <input type="text" name="port" placeholder="3306">
            </div>
            <div class="lanter-field">
                <label>Banco</label>
                <input type="text" name="database">
            </div>
            <div class="lanter-field">
                <label>Usuário</label>
                <input type="text" name="user">
            </div>
            <div class="lanter-field">
                <label>Senha</label>
                <input type="password" name="password">
            </div>
            <button class="lanter-btn" type="submit">Conectar</button>
        </form>
        HTML);}function lanter_action_logout(Lanter_Config$config,Lanter_Auth$auth,?array$connection):void{$auth->logout();lanter_redirect('login');}function lanter_action_dashboard(Lanter_Config$config,Lanter_Auth$auth,?array$connection):void{$driver=lanter_make_driver($connection);$tables=$driver->listTables();$activeTable=$_GET['table']??null;if($activeTable===null||!in_array($activeTable,$tables,true)){lanter_render_layout('Dashboard','<div class="lanter-empty">Selecione uma tabela na barra lateral.</div>',$tables);return;}$page=max(1,(int)($_GET['page']??1));$perPage=25;$rows=$driver->fetchRows($activeTable,$perPage,($page-1)*$perPage);$total=$driver->countRows($activeTable);$content=lanter_render_data_table($activeTable,$rows,$total,$page,$perPage);lanter_render_layout('Dados: '.$activeTable,$content,$tables,$activeTable);}function lanter_render_data_table(string$table,array$rows,int$total,int$page,int$perPage):string{if($rows===[]){return'<div class="lanter-empty">A tabela "'.htmlspecialchars($table).'" está vazia.</div>';}$columns=array_keys($rows[0]);$head='<tr>'.implode('',array_map(static fn(string$c):string=>'<th>'.htmlspecialchars($c).'</th>',$columns)).'</tr>';$body='';foreach($rows as$row){$body.='<tr>'.implode('',array_map(static fn($value):string=>'<td>'.htmlspecialchars((string)$value).'</td>',$row)).'</tr>';}$lastPage=(int)ceil($total/$perPage);return<<<HTML
        <div class="lanter-panel">
            <table class="lanter-table">
                <thead>{$head}</thead>
                <tbody>{$body}</tbody>
            </table>
        </div>
        <p style="color:var(--text-muted);font-size:12px;margin-top:8px">
            Página {$page} de {$lastPage} · {$total} registros
        </p>
        HTML;}function lanter_action_table_structure(Lanter_Config$config,Lanter_Auth$auth,?array$connection):void{$driver=lanter_make_driver($connection);$tables=$driver->listTables();$activeTable=$_GET['table']??null;if($activeTable===null||!in_array($activeTable,$tables,true)){lanter_redirect('dashboard');return;}$columns=$driver->describeTable($activeTable);$rows='';foreach($columns as$column){$keyBadge=$column['key']!==''?'<span class="lanter-key">'.htmlspecialchars($column['key']).'</span>':'';$nullable=$column['nullable']?'SIM':'NÃO';$default=$column['default']??'';$rows.='<tr>'.'<td>'.htmlspecialchars($column['name']).'</td>'.'<td>'.htmlspecialchars($column['type']).'</td>'.'<td>'.$nullable.'</td>'.'<td>'.htmlspecialchars((string)$default).'</td>'.'<td>'.$keyBadge.'</td>'.'</tr>';}$content=<<<HTML
        <div class="lanter-panel">
            <table class="lanter-table">
                <thead><tr><th>Coluna</th><th>Tipo</th><th>Permite Nulo</th><th>Padrão</th><th>Chave</th></tr></thead>
                <tbody>{$rows}</tbody>
            </table>
        </div>
        HTML;lanter_render_layout('Estrutura: '.$activeTable,$content,$tables,$activeTable);}function lanter_make_driver(array$connection):Lanter_DriverInterface{$driver=match($connection['driver']??'sqlite'){'mysql','mariadb'=>new Lanter_MysqlDriver(),default=>new Lanter_SqliteDriver(),};$driver->connect($connection);return$driver;}function lanter_redirect(string$action):void{$query=$action===''?'':'?action='.$action;header('Location: '.strtok($_SERVER['REQUEST_URI'],'?').$query);exit;}function lanter_run():void{if(session_status()!==PHP_SESSION_ACTIVE){session_start();}$config=Lanter_Config::resolve();$auth=new Lanter_Auth($config);$connection=$auth->resolveConnection();$action=$_GET['action']??'dashboard';$actions=['login'=>'lanter_action_login','logout'=>'lanter_action_logout','dashboard'=>'lanter_action_dashboard','table_structure'=>'lanter_action_table_structure',];$handler=$actions[$action]??'lanter_action_dashboard';$publicActions=['login'];if($connection===null&&!in_array($action,$publicActions,true)){lanter_action_login($config,$auth,null);return;}$handler($config,$auth,$connection);}function lanter_css():string{return':root { --bg: #09090b; --bg-soft: #18181b; --border: #27272a; --text: #f4f4f5; --text-muted: #a1a1aa; --brand: #10b981; --brand-soft: rgba(16, 185, 129, 0.12); --danger: #f43f5e; --font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; --font-mono: "SFMono-Regular", Consolas, "Liberation Mono", monospace; } * { box-sizing: border-box; } body { margin: 0; background: var(--bg); color: var(--text); font-family: var(--font-sans); font-size: 14px; } a { color: inherit; text-decoration: none; } .lanter-shell { display: flex; min-height: 100vh; } .lanter-sidebar { width: 240px; background: var(--bg-soft); border-right: 1px solid var(--border); display: flex; flex-direction: column; padding: 16px 12px; } .lanter-brand { display: flex; align-items: center; gap: 8px; padding: 8px; margin-bottom: 16px; font-weight: 600; color: var(--brand); } .lanter-brand-icon { font-size: 18px; } .lanter-table-list { flex: 1; display: flex; flex-direction: column; gap: 2px; overflow-y: auto; } .lanter-table-link { padding: 8px 10px; border-radius: 8px; color: var(--text-muted); font-family: var(--font-mono); font-size: 12px; } .lanter-table-link:hover { background: var(--border); color: var(--text); } .lanter-table-link.active { background: var(--brand-soft); color: var(--brand); border: 1px solid rgba(16, 185, 129, 0.3); } .lanter-logout { padding: 8px 10px; color: var(--danger); font-size: 12px; } .lanter-main { flex: 1; padding: 24px; overflow-x: auto; } .lanter-panel { background: var(--bg-soft); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; } .lanter-table { width: 100%; border-collapse: collapse; font-size: 12px; font-family: var(--font-mono); } .lanter-table th, .lanter-table td { padding: 10px 14px; text-align: left; border-bottom: 1px solid var(--border); } .lanter-table th { color: var(--text-muted); text-transform: uppercase; font-size: 11px; letter-spacing: 0.04em; } .lanter-table tr:hover td { background: rgba(255, 255, 255, 0.02); } .lanter-key { color: #f59e0b; font-weight: 600; } .lanter-login { max-width: 360px; margin: 80px auto; display: flex; flex-direction: column; gap: 12px; } .lanter-login h1 { color: var(--brand); margin: 0 0 8px; } .lanter-field { display: flex; flex-direction: column; gap: 4px; } .lanter-field label { font-size: 12px; color: var(--text-muted); } .lanter-field input, .lanter-field select { background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px; color: var(--text); font-size: 13px; } .lanter-field input:focus, .lanter-field select:focus { outline: none; border-color: var(--brand); } .lanter-btn { background: var(--brand); color: #052e1f; border: none; border-radius: 8px; padding: 10px 14px; font-weight: 600; font-size: 13px; cursor: pointer; } .lanter-btn:hover { filter: brightness(1.05); } .lanter-tabs { display: flex; gap: 4px; margin-bottom: 16px; border-bottom: 1px solid var(--border); } .lanter-tab-link { padding: 8px 14px; font-size: 12px; color: var(--text-muted); border-bottom: 2px solid transparent; } .lanter-tab-link:hover { color: var(--text); } .lanter-empty { color: var(--text-muted); padding: 40px; text-align: center; }';}lanter_run();