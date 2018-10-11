<?php
	
$config = [

	'options'=>[
		'logpath' => 'log',					// путь, куда писать логи
		'logger' => true,					// вести логирование
		'conlog' => true,					// выводить логи на консоль
		'debug' => true,					// логировать отладочные сообщения
		'skiptransfer' => true,				// не отправлять информацию в Itera
		'periodattempt' => 10,				// периодичность (в минутах) попыток отправить информацию в Itera (по умолчанию 10мин)
		'exectout' => 60,					// допустимое время работы программы (в минутах)
	],

	'db'=>[
		'dbserver'	=>'db_server_ip',
		'username'	=>'db_username',
		'password'	=>'db_login',
		'dbname'	=>'db_name',
	],

	'bsmartapi'=>[
		'username'		=> 'username',
		'password'		=> 'password',
		'url_login'		=> 'API_login_url',
		'url_addphoto'	=> 'API_add_sw_photo_url',
	],

	'SWPictures' => [
		'maxfilelen' => 5000000,										// Максимально допустимый размер передаваемого файла
		'PicPath' => 'path_to_tickets\\_DataStore\\Equipment\\',		// Путь, где лежат файлы картинок
	],

];	

?>
