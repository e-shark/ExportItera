<?php
//---------------------
// Должны быть описаны в главном модуле
//const SCRIPTVERSION = "v.0.1";	
//const SCRIPTNAME="scriptname";	
//---------------------
require_once __DIR__.'\\'.SCRIPTNAME.'_config.php';

$TaskStartTime = time();
$ch;								// Дискриптор сеанса CURL
$curloptions = [
	CURLOPT_HTTPGET=>TRUE,
	CURLOPT_HEADER=>0,
	CURLOPT_RETURNTRANSFER=>TRUE,
	CURLOPT_TIMEOUT=>30,	
	CURLOPT_COOKIEFILE=>"",			// Set an empty string to enable Cookie engine!!!
];
$LogPath = __DIR__;		// по умолчанию логи пишем в текущий каталог

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
	global $LogPath;

	$dbgfn = (empty($LogPath)?__DIR__:$LogPath).DIRECTORY_SEPARATOR.date('ymd_').SCRIPTNAME."dbg.txt";
	
	if ($config['options']['conlog']) echo stripWhitespaces($message)."\n";
	
	if( FALSE === ( $dbgfp = fopen( $dbgfn, "a" ) ) ) return FALSE;
	fputs($dbgfp, date('H:i:s').' '.$message."\n");
	fclose($dbgfp);
	return TRUE;
}

//--------------------------------------------------------------------------------------
//--------------------------------------------------------------------------------------
function SetLogPath($path){
	global $LogPath;
	$res = __DIR__;
	if (!empty($path)) {
		if (!is_dir($path)) {
			if (mkdir($path,0777,TRUE))
				$res = $path;
			else
				logger("Can't create LogDir ".$path);
		}else $res = $path;
	}
	$LogPath = $res;
	return $res;
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
	//$sql = "UPDATE batchscriptlog SET starttime = NULL WHERE scriptname='".SCRIPTNAME."'; ";
	$sql = "DELETE FROM batchscriptlog WHERE scriptname='".SCRIPTNAME."'";
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
	$ch = curl_init($request);
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
// 	Проверка на время выполнения скрипта
//	Возвращает TRUE, если время выполнения скрипта превысило заданное конфигурацией
//--------------------------------------------------------------------------------------
function IsExecTimeExceeded()
{
	global $TaskStartTime;
	global $config;

	$result =false;
	$TaskExecTime = time() - $TaskStartTime;
	if ( $TaskExecTime/60 > $config['options']['exectout']) {
		logger("Execution time exceeded (".$config['options']['exectout']." min max). Execution aborted.");
		$res = true;
	}
	return $res;
}

//--------------------------------------------------------------------------------------
//	Запуск службы
//  Отсюда вызывается MAIN_LOOP() головного модуля
//--------------------------------------------------------------------------------------
function MAIN_START()
{
	global $config;
	global $ch;
	global $TaskStartTime;

	SetLogPath($config['options']['logpath']);
	$scriptstat = stat(__FILE__);
	logger( "************************************************************************");
	logger( "Start ".__FILE__.' '.SCRIPTVERSION." Build ".date('Y-m-d H:i:s',$scriptstat['mtime']).' Size: '.$scriptstat['size'] );
	logger( "Open DB ".$config['db']['dbserver'].":".$config['db']['username']);
	//---Open DB connection
	if( ! ( $db = @mysql_connect ( $config['db']['dbserver'], $config['db']['username'], $config['db']['password'] ) ) ) {
		logger( "Error: failed connected to DB, error: ".mysql_error() );
		exit(-1);
	}
	if ( mysql_select_db($config['db']['dbname']) ) {

		if (empty($config['options']['exectout'])) 
			$config['options']['exectout'] = 30;			// По умолчанию разрешаем скрипту работать не более 30 минут

		if (empty($config['options']['skiptransfer'])) 		// По умолчанию запрещаем отсылку данных
			$config['options']['skiptransfer'] = true;

	    if (ChekFoStartPermission()) {
			if (LoginForItera()) {

				MAIN_LOOP();

			}else{
				logger("Can't login to Itera server.");
			}
			curl_close( $ch );
			DeleteStartMarker();
		}else{
			logger("Haven't start permission. Export is not started.");
		}
	}else{
		logger( "Error: unable select DB, error: ".mysql_error() );
	}
	mysql_close($db);
	$TaskExecTime = time() - $TaskStartTime;
	logger("Done. Execution time ".$TaskExecTime." sec (".round( (time() - $TaskStartTime)/60 , 2)." min).");
}

//--------------------------------------------------------------------------------------
//	Пример построения основного цикла службы
//--------------------------------------------------------------------------------------
function MAIN_LOOP_Example()
{
	$sql = "SELECT * from table ;";
	logger($sql);

	if( FALSE !== ( $cursor = mysql_query($sql) ) ) {
		$num_rows = mysql_num_rows($cursor);
		logger(mysql_num_rows($cursor).' rows have fetched from tale');
		if ($num_rows > 0) {

			$COUNT = 0;
			while( $row = mysql_fetch_assoc( $cursor ) ) {
				logger("--------------------------------------------------------");
				$COUNT += 1;


				// DO_SOME_WITH_REC( $row );


				//usleep(100e3);	// задержка на 100 миллисекунд
				//if ($COUNT>5) break;	// !!! ДЛЯ ОТЛАДКИ
				if ($COUNT % 10 == 0) logger("next 10 (".$COUNT.")");

				// Проверка на время выполнения скрипта
				if ( IsExecTimeExceeded() ) break;
			} //while
		}
		mysql_free_result($cursor);	
		//logger("Exported  successfully ".$counerDone." of ".$counerAll." records");
	}else{
		logger("Can't read table");
	}

}

?>
