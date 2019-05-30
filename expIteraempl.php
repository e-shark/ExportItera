<?php
const VERSION_expiterempl="v.0.1";
require_once __DIR__.'\\expiterempl_config.php';
const SCRIPTNAME="expiterempl";

$ridtbl = [];
$ch;
$curloptions = [
	CURLOPT_HTTPGET=>TRUE,
	CURLOPT_HEADER=>0,
	CURLOPT_RETURNTRANSFER=>TRUE,
	CURLOPT_TIMEOUT=>30,	//4
	CURLOPT_COOKIEFILE=>"",	// Set an empty string to enable Cookie engine!!!
];

$IteraUserTable = NULL;		// Здесь сохраняется таблица юзеров Itera
$CountInsert = 0;			// Счетчик добавленных в Itera юзеров
$CountUpdate = 0;			// Счетчик измененных в Itera юзеров
$CountInsFail = 0;			// Счетчик неудачнык попыток заинсертить юзера
$CountUpdFail = 0;			// Счетчик неудачнык попыток заинсертить юзера

//--------------------------------------------------------------------------------------
//	Очистить текст от непечатаемых символов
function stripWhitespaces($string) {
  $old_string = $string;
  $string = strip_tags($string);
  $string = preg_replace('/([^\pL\pN\pP\pS\pZ])|([\xC2\xA0])/u', ' ', $string);
  $string = str_replace('  ',' ', $string);
  $string = trim($string);
  
  if ($string === $old_string) {
    return $string;
  } else {
    return stripWhitespaces($string); 
  }  
}

//--------------------------------------------------------------------------------------
/**
 *	Logger to file
 * @param string message
 * @return boolean result
 */
function logger($message)
{
	global $config;

	$dbgfn = __DIR__."\\".date('ymd_').basename(__FILE__,".php")."dbg.txt";
	
	if ($config['options']['conlog']) echo stripWhitespaces($message)."\n";
	
	if( FALSE === ( $dbgfp = fopen( $dbgfn, "a" ) ) ) return FALSE;
	fputs($dbgfp, date('H:i:s').' '.$message."\n");
	fclose($dbgfp);
	return TRUE;
}


//--------------------------------------------------------------------------------------
//	Проверяет, не запущена ли уже копия задачи,
//  если копий нет, ставит в базе отметку о запуске
//--------------------------------------------------------------------------------------
function ChekFoStartPermission()
{
	global $config;
	$res = false;
	$sql = "SELECT starttime, TIMESTAMPDIFF(MINUTE, starttime, now()) as extime from batchscriptlog where scriptname='".SCRIPTNAME."';";
    if( FALSE !== ( $dscursor = mysql_query($sql) ) ) {
    	$dsrows = mysql_num_rows($dscursor);
    	if ($dsrows>0){
    		// Есть запись. Значит задача уже запускалась
    		$dsrow = mysql_fetch_assoc( $dscursor );
    		// Проверяем, как давно был запуск задачи
    		if ( is_null($dsrow['extime']) || ($dsrow['extime'] > $config['options']['exectout']) ) {
    			if (($dsrow['extime'] > $config['options']['exectout']))
    				logger("StartTime label (".$dsrow['extime']." min ago) out of timeout (".($config['options']['exectout'])." min) !");
    			// Превыщен лимит. Подразумеваем, что процесс, оставивший пометку в базе, просто звершился крахом, не успев снять пометку из базы
    			$sql = "UPDATE batchscriptlog SET starttime = now() WHERE scriptname='".SCRIPTNAME."'; ";
    			if( mysql_query($sql) ) {
					logger("Update table batchscriptlog ");
	    			$res = true;
    			}else
					logger("Can't update table batchscriptlog");
    		}else{
				logger("Task already started!");
    		}
        }else{ 
        	// Записей нет, значит уже запущенных задач нет
  			$sql = "INSERT INTO batchscriptlog (scriptname, starttime) VALUES ('".SCRIPTNAME."', now()); ";
			if( mysql_query($sql) ) {
    			$res = true;
				logger("Insert in table batchscriptlog");
			}
			else
				logger("Can't insert in table batchscriptlog");
		}
		mysql_free_result($dscursor);
	}else{
		logger("Can't read table batchscriptlog");
	}
	return $res;
}

//--------------------------------------------------------------------------------------
// Удаляет из базы отметку о запуске задачи
//--------------------------------------------------------------------------------------
function DeleteStartMarker()
{
	$sql = "UPDATE batchscriptlog SET starttime = NULL WHERE scriptname='".SCRIPTNAME."'; ";
	logger("Delete StartMarker from table batchscriptlog");
	if( FALSE == mysql_query($sql) ) 
		logger("Can't delete marker from table batchscriptlog");
}


//--------------------------------------------------------------------------------------
//	Процедура логина на сервере Итера
//--------------------------------------------------------------------------------------
function LoginForItera()
{
	$res = false;
	global $config;
	global $curloptions;
	global $ch;

	$request = $config['bsmartapi']['url_login'];
	$ch=curl_init($request);
	curl_setopt_array($ch,$curloptions);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_HTTPHEADER, ["Referer: ".$request]);  
	curl_setopt($ch, CURLOPT_POSTFIELDS, ['Login' => $config['bsmartapi']['username'], 'Password'=>$config['bsmartapi']['password']]);
	if ( FALSE === ( $result = curl_exec($ch) ) ) {
			$txresult = 'Error:network failure when auth';
			$res = false;
	}else{
		$curlinfo=curl_getinfo($ch,CURLINFO_COOKIELIST);
		$curlinfostr = (is_array($curlinfo)) ? implode(' ',$curlinfo) : $curlinfo;
		if (FALSE !== strpos($curlinfostr,"ASPXAUTH")) {
			$txresult = 'Auth Ok!';
			$res = true;
		}else{
			$txresult = 'Error:failed to Auth';
			$res = false;
		}	
	}

	return $res;
}

//--------------------------------------------------------------------------------------
//	Получаем таблицу юзеров на сайте Itera
//--------------------------------------------------------------------------------------
function GetIteraUsers()
{
	global $config;
	global $curloptions;
	global $ch;
	$res = NULL;

	if ($config['options']['debug']) logger("get Itera users list");

	$request = $config['bsmartapi']['url_getuser'];

	curl_setopt($ch, CURLOPT_URL, $request);
	curl_setopt_array($ch, $curloptions);
	$result = curl_exec($ch);
	if(FALSE!==$result)
	{
		$result = mb_convert_encoding($result,'UTF-8');
		$jsonAnswer = json_decode($result,true);
		if(!empty($jsonAnswer)) {
			$res = $jsonAnswer;
			//logger(serialize($res));
			$cnt = count($res['Records']);
			if (!empty($cnt)){
				$res = $jsonAnswer;
				logger($cnt." records received from Itera");	
				//logger("[0]id: ".$res['Records'][0]['id']);
				//logger("[0]org: ".$res['Records'][0]["organization_name"]);
				//logger("rec[0]: ".json_encode($res['Records'][0]));
			}
		}
	}


	return $res;
}

//--------------------------------------------------------------------------------------
//	Ищет юзера (по ID) в таблице юзеров Itera
//--------------------------------------------------------------------------------------
function FindIteraUserByRID($UserTable, $UserId){
	$res = NULL;
	if (empty($UserTable)) return $res;
	if (empty($UserId)) return $res;
	foreach($UserTable['Records'] as $rec){
		if ($UserId == $rec['id']) {
			$res = $rec;
			break;
		}
	}
	return $res;
}

//--------------------------------------------------------------------------------------
//--------------------------------------------------------------------------------------
function GetFullNameFromRec($rec)
{
	$res = "";
	switch($rec['occupation_id']){
		case 3:
			$res = "Электромеханик_".$rec['id'];
			break;
		case 4:
			$res = "Электромеханик_ЛАС_".$rec['id'];
			break;

		case 25:
			$res = "Электромонтер_".$rec['id'];
			break;
		case 26:
			$res = "Электромонтер_ЛАС_".$rec['id'];
			break;
			
		default:
			if (!empty($rec['lastname'])) $res .= $rec['lastname'];
			if (!empty($rec['firstname'])) {
				if (!empty($res)) $res .= " ";
				$res .= $rec['firstname'];
			}
			if (!empty($rec['patronymic'])) {
				if (!empty($res)) $res .= " ";
				$res .= $rec['patronymic'];
			}
	}
	return $res;
}

//--------------------------------------------------------------------------------------
//	Ищет юзера (по ФИО) в таблице юзеров Itera
//--------------------------------------------------------------------------------------
function FindIteraUserByFullName($UserTable, $FullName){
	$res = NULL;
	if (empty($UserTable)) return $res;
	if (empty($FullName)) return $res;
	foreach($UserTable['Records'] as $rec){
		if ($FullName == $rec['name']) {
			$res = $rec;
			break;
		}
	}
	return $res;
}
//--------------------------------------------------------------------------------------
//	Получить ID должности Itera по Id должности нашей БД
//--------------------------------------------------------------------------------------
function CalcIteraPositionID($id)
{
	$res = NULL;
	global $config;
	$val = $config['cross']['Position'][$id];
	if (!empty($val)) $res = $val;
	return $res;
}

//--------------------------------------------------------------------------------------
//	Создает (или редактирует) в Итере запси пользователя из $BDRec.
//	Усли задан параметр $IteraRec - то UPDATE, если не задан - INSERT.
//	Возвращет ID записи в Итере, или NULL в случе неудачи.
//--------------------------------------------------------------------------------------
function IteraUserEdit($BDRec, $IteraRec = NULL)
{
	$res = NULL;
	$insupdOk = false;

	global $config;
	global $curloptions;
	global $ch;
	global $CountInsert;
	global $CountUpdate;
	global $CountInsFail;
	global $CountUpdFail;


	if ($config['options']['skiptransfer']) {
		if ($config['options']['debug']) logger(" ... skiptransfer by config.");
		return $res;		// Если запрет передачи данных
	}

	$iteraAPIurl = $config['bsmartapi']['url_edituser'];
	if (!empty($IteraRec)) {
		$iteraAPIurl .= "?id=".$IteraRec;
		$opname = "UPDATE";
	}else		
		$opname = "INSERT";

	$postdata=[			
		'@login' => "user".$BDRec['id'],
		'@passhash' => "user".$BDRec['id'],
		'@name' => GetFullNameFromRec( $BDRec ),
		'@organization_id' => 1,			// - идентификатор организации, ХГЛ - 1
		//'@areas' => 10,						// - ОДС На текущий момент можно передавать @areas=10
		'@is_active' => 0+$BDRec['isemployed'],
		'@position_id' => CalcIteraPositionID($BDRec['occupation_id']),
	];
	if ($config['options']['debug']) {
		logger( "Itera ".$opname." addr: ".$iteraAPIurl );
		logger( "Itera ".$opname." postdata: ".json_encode((array)$postdata,JSON_UNESCAPED_UNICODE) );
	}
	curl_setopt_array($ch,$curloptions+[
		CURLOPT_URL=>$iteraAPIurl,
		CURLOPT_POST=>1,
		CURLOPT_POSTFIELDS=>$postdata,
	]);
	$txresult = curl_exec($ch); 
	if( FALSE === $txresult )
		logger('Error: failed to '.$opname.' user');
	else {
		$txresult = mb_convert_encoding($txresult,"UTF-8");
		if ($config['options']['debug']) {
			logger("Itera ".$opname." result: ".$txresult);
		}
		$jsonAnswer = json_decode($txresult,true);
		if ( is_array($jsonAnswer) ) 
			if( FALSE !== mb_strpos($jsonAnswer['Status'],"Ok")) {
				$insupdOk = true;
				$res =  $jsonAnswer['Result'];
			}
	}
	if ($insupdOk) {
		if (empty($IteraRec)) $CountInsert++;
		else $CountUpdate++;
	}else{
		if (empty($IteraRec)) $CountInsFail++;
		else $CountUpdFail++;
	}
	return $res;
}

//--------------------------------------------------------------------------------------
//	Обновляет RID в записи пользователя в нашей таблице
//--------------------------------------------------------------------------------------
function UpdateBDUser($id, $rid)
{
	global $config;
	$res = false;
	if (empty($id)) return $res;
	if (empty($rid)) $rid = 'null';
	if ($config['options']['debug']) logger("UPDATE employee  (id:".$id.", remoteid:".$rid.") ");
	// Апдейтим таблицу БД
	$tsql = "UPDATE employee SET remoteid=".$rid." WHERE id=".$id.";";
	if( FALSE == ( $curupd = mysql_query($tsql) ) ) {
		logger( "Error: failed UPDATE employee (id:".$id.", remoteid:".$rid."), error: ".mysql_error() );
	}else $res = true;
	return $res;
}

//--------------------------------------------------------------------------------------
//	Определеяе, нужно ли обновить запись в Itera
//	$BDRec - запись из таблици employee
//	$IU - запись пользователя в Itera (страница User)
//	Возвращаут true, если требуется обновить запись в Itera
//--------------------------------------------------------------------------------------
function IsUpdateNeeds($BDRec,$IU)
{
	$res = false;
	if ($BDRec['isemployed'] != $IU['is_active']) $res = true;
	return $res;
}

//--------------------------------------------------------------------------------------
//	Обработка записи пользователя нашей табицы
//--------------------------------------------------------------------------------------
function RecordProcessing($rec)
{
	global $config;
	global $curloptions;
	global $ch;
	global $IteraUserTable;
	global $CountInsFail;

	// определеям, надо ли экспортить юзера
	$op = false;
	if (!empty($rec['occupation_id'])) 
		$op = array_search($rec['occupation_id'], $config['options']['OccupationPermit']);
	if (!$op) {
		if ($config['options']['debug']) logger("processing skiped  occupation_id:".$rec['occupation_id']." (user id:".$rec['id'].")");
		return;
	}


RecProcLabel1:
	$IU = NULL;
	if (empty($rec['remoteid'])){
		// Если нет RID, пытаемя найти такого юзера в Итере по ФИО
		$IU = FindIteraUserByFullName( $IteraUserTable, GetFullNameFromRec( $rec ) );
		if (empty($IU)) {
			// Юзер не найден в Итере
			// INSERT юзера 
			if (1 == $rec['isemployed']) {																// Инсертим только ныне работающих юзеров
				if ($config['options']['debug']) logger("try to Itera INSERT  (id:".$rec['id'].")");
				$rid = IteraUserEdit($rec);
				if (!empty($rid)){
					if ($config['options']['debug']) logger("Inserting Ok. Updating BD for  id:".$rec['id'].", set remoteid=".$rid);
					UpdateBDUser($rec['id'], $rid);
				}else{
					$CountInsFail++;
					logger('FAIL Itera ISERT for '.$rec['lastname']." ".$rec['firstname']." ".$rec['patronymic']." id:".$rec['id']);
				}
			}
		}else{
			// Юзер найден в Итере по ФИО.
			// Записываем себе в базу его RID
			logger(' find in itera by FIO rid:'.$rec['remoteid']." { id:".$IU['id'].", name:".$IU['name'].", position_name:".$IU['position_name'].", role_name: ".$IU['role_name']."}" );
 			logger('Set RID:'.$IU['id'].' for '.$rec['lastname']." ".$rec['firstname']." ".$rec['patronymic']." id:".$rec['id']);
			UpdateBDUser($rec['id'], $IU['id']);
		}
	}else{
		// Ищеме в Итере юзера по RID
		$IU = FindIteraUserByRID($IteraUserTable, $rec['remoteid']);		// ищем юзера в списке Itera по remoteid
		if (empty($IU)) {
 			logger('NOT FOUND RID:'.$rec['remoteid'].' for '.$rec['lastname']." ".$rec['firstname']." ".$rec['patronymic']." id:".$rec['id']);
			UpdateBDUser($rec['id'], NULL);
			$rec['remoteid'] = NULL;
			goto RecProcLabel1;
		}
	}
	
	if (!empty($IU)){
		// Юзер найден в базе Итеры
		if (IsUpdateNeeds($rec,$IU)){
			if ($config['options']['debug']) logger("try to Itera UPDATE  (id:".$rec['id'].", remoteid:".$rec['remoteid'].") ");
			IteraUserEdit($rec, $IU['id']);
		}
	}

	return;
}

//--------------------------------------------------------------------------------------
//	MAIN LOOP
//--------------------------------------------------------------------------------------
$scriptstat = stat(__FILE__);
$TaskStartTime = time();
logger( "*****************************************************************************************************************");
logger( "Start ".__FILE__.' '.VERSION_expiterempl." Build ".date('Y-m-d H:i:s',$scriptstat['mtime']).' Size: '.$scriptstat['size'] );
logger( "Open DB ".$config['db']['dbserver'].":".$config['db']['username']);
//---Open DB connection
if( ! ( $db = @mysql_connect ( $config['db']['dbserver'], $config['db']['username'], $config['db']['password'] ) ) ) {
	logger( "Error: failed connected to DB, error: ".mysql_error() );
	exit(-1);
}
logger( "Open Ok ");

mysql_select_db($config['db']['dbname']);

	if (empty($config['options']['exectout'])) 
		$config['options']['exectout'] = 30;

    if (ChekFoStartPermission()|| true) {

		if (LoginForItera()) {

			$sql = "SELECT * FROM employee; ";
			//$sql = "SELECT * FROM employee WHERE id = 7 or id = 48; ";   // ! ! ! !   ДЛЯ ОТЛАДКИ  ! ! ! ! !

			$IteraUserTable = GetIteraUsers();
			if (NULL != $IteraUserTable){

				logger($sql);
				if( FALSE !== ( $cursor = mysql_query($sql) ) ) {
					$num_rows = mysql_num_rows($cursor);
					logger(mysql_num_rows($cursor).' rows have fetched from employee');
					if ($num_rows > 0) {

							$COUNT = 0;
							while( $row = mysql_fetch_assoc( $cursor ) ) {
								//logger("--------------------------------------------------------");
								$COUNT += 1;

								RecordProcessing($row);

								//if ($COUNT>0) break;	// !!! ДЛЯ ОТЛАДКИ
								//if ($COUNT % 10 == 0)  logger("next 10 (".$COUNT.")");

								$TaskExecTime = time() - $TaskStartTime;
								if ( $TaskExecTime/60 > $config['options']['exectout']) {
									logger("Execution time  exceeded (".$config['options']['exectout']." min max). Execution aborted.");
									break;							// Прерываем выполнение цикла
								}

							} //while
					}
					mysql_free_result($cursor);	
					logger("Insert record: ".$CountInsert.", updated record: ".$CountUpdate);
					if ($CountInsFail || $CountUpdFail)
						logger("Insert fail: ".$CountInsFail.", updated fail: ".$CountUpdFail);
				}else{
					logger("Can't read table");
				}
			}else{
				logger("Can't get Itera users table");
			}
		}else{
			logger("Can't login to Itera server.");
		}
		curl_close( $ch );
		DeleteStartMarker();
	}else{
		logger("Haven't start permission. Export is not started.");
	}

mysql_close($db);
$TaskExecTime = time() - $TaskStartTime;
logger("Done. Execution time ".$TaskExecTime." sec (".round( (time() - $TaskStartTime)/60 , 2)." min).");

?>
