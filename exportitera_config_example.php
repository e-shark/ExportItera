<?php
	
$config = [
	'options'=>[
		'logger' => true,
		'conlog' => false,
		'debug' => true,
		'skiptransfer' => true,
		'periodattempt' => 30,				// периодичность (в минутах) попыток отправить информацию в Itera (по умолчанию 10мин)
		'daydepth' => 5,					// глубина (в сутках) анализа таблицы экспорта (по умолчанию 3 дня)
		'manualpermission' => false,		// разрешить ручную принудительную отправку заявок (с флагом manualcmd)
		'exectout' => 60,					// допустимое время работы программы (в минутах)
	],

	'db'=>[
		'dbserver'	=>'DB_server_IP',
		'dbname'	=>'DB_name',
		'username'	=>'DB_user',
		'password'	=>'DP_password',
	],

	'bsmartapi'=>[
		'username'	=> 'api_user',
		'password'	=> 'api_password',
		'url_login' 	 => 'http://api_url/Account/Login',
		'url_getticket'  => 'http://api_url/ds/Ticket',
		'url_insupd' 	 => 'http://api_url/edt/Ticket/Post',
		'url_setstatus'	 => 'http://api_url/Ticket/SetStatus',
		'url_setcomment' => 'http://api_url/edt/TicketComment/Post',
	],
];	

?>
