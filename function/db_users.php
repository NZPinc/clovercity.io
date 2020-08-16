<?php
setlocale(LC_ALL, "ru_RU.UTF-8");

	// Устанавливаем параметры
	$action = $_POST['action'];
	$user_id = (int) $_POST['user_id'];
	$session_id = $_POST['session_id'];
	
	// Если action пустой, то прерываем скрипт
	if (!empty($action) and !empty($user_id) and !empty($session_id) and $action != "login" and $action != "register_user" and $action != "create_reset_code_account" and $action != "reset_password_account" and $action != "approve_mail_user") {
		
		// Подключаем системные файл
		include_once 'db_func.php';
		
		// Получаем session_id пользователя
		$sth = $dbh->prepare('SELECT session_id FROM users WHERE id = ?');
		$sth->execute(array($user_id));

		// Получаем id сессии с базы данных
		$sessions = $sth->fetchAll(PDO::FETCH_ASSOC);
		$_session_id = $sessions[0]['session_id'];
		
		// Если id сессий не совпадает, обрываем связь
		if ($_session_id !== $session_id) {
			$message = ['status' => 'Отказано в доступе'];
			echo json_encode($message);
			
			// Закрываем соединение с базой
			$dbh = null;
			die();
		}
		
	} else {
		
		// Проверяем параметр action, если он login, то не завершаем работу скрипта
		if ($action == "login" or $action == "register_user" or $action == "create_reset_code_account" or $action == "reset_password_account" or $action == "approve_mail_user") {
			
			// Подключаем системные файл
			include_once 'db_func.php';
			
		} else {
			
			// Закрываем соединение с базой
			$dbh = null;
			die();
		
		}
		
	}


//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Авторизация пользователя
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


switch ($action) {
case 'login':


	// Проверяем данные на соответствие
	try {

		if (empty($_POST['login'])) {
			throw new Exception('Введите логин или e-mail');
		}
		if (empty($_POST['password'])) {
			throw new Exception('Введите пароль');
		}

		// Устанавливаем значения
		$login = $_POST['login'];
		$password = $_POST['password'];

		$sth = $dbh->prepare("SELECT *, (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) AS display_name FROM users WHERE mail = ? or nickname = ?;");
		$sth->execute(array($login, $login));
					
		// Обработка полученных данных
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($result) == 1) {
			foreach ($result as $row) {

				// Генерируем хэш пароля из соли и сверяем данные
				$hash_password = hash('sha256', $password . $row['salt']);
				
				// Сверяем почту
				if($login != $row['mail'] and $login != $row['nickname']){
					throw new Exception('Логин или E-mail не совпадает');
				}
				
				// Сверяем хеш пароля
				if ($hash_password != $row['password']) {
					throw new Exception('Пароль не совпадает');
				}

				// Сверяем на блокировку пользователя
				if ($row['block'] == 1) {
					throw new Exception('Аккаунт заблокирован');
				}

				// Обновляем соль и хэш пароля
				$id = $row['id'];
				$newsalt = microtime();
				$newpass = hash('sha256', $password . $newsalt);
				$last_login = date("Y-m-d H:i:s");
				$_session_id = gen_id();
				$stmt = $dbh->prepare('UPDATE users SET password=:password, salt=:salt, last_login=:last_login, session_id=:session_id WHERE id=:id');
				$stmt->execute(array(':password'=>$newpass, ':salt'=>$newsalt, ':last_login'=>$last_login, ':session_id'=>$_session_id, ':id'=>$id));

				// Заносим значения в массив
				$_session_user = [
					'status' => 'success',
					'user_id' => $row['id'],
					'display_name' => $row['display_name'],
					'avatar' => $row['avatar'],
					'session_id' => $_session_id,
					'version_client' => last_version_client
				];

				// Выводим сообщение
				echo json_encode($_session_user);

			}
		} else {
			throw new Exception('Пользователь не найден');
		}

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	

//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Загрузка пользователей
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'loading_users':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$user_id = (int) $_POST['user_id'];
		$search = $_POST['search'];
		$bookmarks = (int) $_POST['bookmarks'];
		
		// Проверка на id
		if (empty($user_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на поиск пользователей
		if (!empty($search)) {
			$search = ' AND (first_name LIKE "%'.$search.'%" OR last_name LIKE "%'.$search.'%" OR nickname LIKE "%'.$search.'%")';
		} else {
			$search = "";
		}
		
		// Проверка на список пользователей
		if ($bookmarks == 0) {
			
			// Подготавливаем запрос в базу данных
			$sth = $dbh->prepare("SELECT U.id,
										   U.avatar,
										   (
											   SELECT MAX(last_action) AS last_action
												 FROM (
														  SELECT last_login AS last_action
															FROM users WHERE id = U.id
														  UNION
														  SELECT MAX(date) AS last_action
															FROM messages WHERE user_id = U.id
														  UNION
														  SELECT MAX(date) AS last_action
															FROM messages_likes WHERE user_id = U.id
														  UNION
														  SELECT MAX(date) AS last_action
															FROM comments WHERE user_id = U.id
													  )
										   )
										   AS last_action,
										   U.block,
										   (
											   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) AS display_name
												 FROM users
												WHERE id = U.id
										   )
										   AS display_name,
										   (
											   SELECT id
												 FROM bookmarks_users AS BU
												WHERE BU.user_one_id = ? AND 
													  BU.user_two_id = U.id
										   )
										   AS bookmark_id
									  FROM users AS U
									 WHERE U.id != ? AND 
										   U.block = 0 $search
									 ORDER BY U.last_login DESC;");
			$sth->execute(array($user_id, $user_id));
			
		}
		
		// Проверка на список закладок
		if ($bookmarks == 1) {
			
			// Подготавливаем запрос в базу данных
			$sth = $dbh->prepare("SELECT U.id,
										   U.avatar,
										   (
											   SELECT MAX(last_action) AS last_action
												 FROM (
														  SELECT last_login AS last_action
															FROM users WHERE id = U.id
														  UNION
														  SELECT MAX(date) AS last_action
															FROM messages WHERE user_id = U.id
														  UNION
														  SELECT MAX(date) AS last_action
															FROM messages_likes WHERE user_id = U.id
														  UNION
														  SELECT MAX(date) AS last_action
															FROM comments WHERE user_id = U.id
													  )
										   )
										   AS last_action,
										   U.block,
										   BU.id AS bookmark_id,
										   BU.user_one_id,
										   (
											   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) AS display_name
												 FROM users
												WHERE id = BU.user_two_id
										   )
										   AS display_name
									  FROM bookmarks_users AS BU
										   JOIN
										   users AS U ON BU.user_two_id = U.id
									 WHERE BU.user_one_id = ? AND 
										   U.block = 0 $search
									 GROUP BY BU.user_two_id
									 ORDER BY U.last_login DESC;
									");
			$sth->execute(array($user_id));
			
		}

		// Выводим сообщение
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		$message = [
			'status' => 'success',
			'sql' => $result
		];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}


//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Открываем пользователя
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'open_user':


	// Проверяем данные на соответствие
	try {

		// Устанавливаем значения
		$user_id = (int) $_POST['user_id'];

		// Проверка на id
		if (empty($user_id)) {
			throw new Exception('Ошибка передачи данных');
		}

		// Подготавливаем запрос в базу данных
		$sth = $dbh->prepare('SELECT U.id,
									   U.avatar,
									   U.block,
									   U.date_register,
									   U.display_name,
									   U.first_name,
									   U.last_name,
									   U.last_login,
									   U.mail,
									   U.nickname,
									   U.write_to_me,
									   (
										   SELECT COUNT(id) 
											 FROM messages
											WHERE user_id = U.id
									   )
									   AS count_messages,
									   (
										   SELECT COUNT(id) 
											 FROM community
											WHERE user_id = U.id
									   )
									   AS count_communnity
								  FROM users AS U
								 WHERE id = ?;');
		$sth->execute(array($user_id));

		// Выводим сообщение
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		// Проверяем количество строк ответа
		if (count($result) !== 1) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		$message = [
			'status' => 'success',
			'sql' => $result
		];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}


//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Открываем пользователя для чтения
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'open_user_read':


	// Проверяем данные на соответствие
	try {

		// Устанавливаем значения
		$user_id = (int) $_POST['user_id_open'];

		// Проверка на id
		if (empty($user_id)) {
			throw new Exception('Ошибка передачи данных');
		}

		// Подготавливаем запрос в базу данных
		$sth = $dbh->prepare("SELECT (SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) AS display_name FROM users WHERE id = ?) AS display_name, avatar, last_login FROM users WHERE id = ?");
		$sth->execute(array($user_id, $user_id));

		// Выводим сообщение
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		// Проверяем количество строк ответа
		if (count($result) !== 1) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		$message = [
			'status' => 'success',
			'sql' => $result
		];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}

	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Обновление пользователя
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'update_user':


	// Проверяем данные на соответствие
	try {

		// Устанавливаем значения
		$mail = trim($_POST['mail']);
		$nickname = $_POST['nickname'];
		$display_name = (int) $_POST['display_name'];
		$write_to_me = (int) $_POST['write_to_me'];
		$first_name = $_POST['first_name'];
		$last_name = $_POST['last_name'];
		$password = $_POST['password'];
		$salt = $_POST['salt'];
		
		// Проверка строки на фхождения
		$preg = "/^[a-zа-яё]{1}[a-zа-яё]*[a-zа-яё]{1}$/iu";
		$preg_alnum = "/^[a-zа-яё\d]{1}[a-zа-яё\d]*[a-zа-яё\d]{1}$/iu";
		$preg_alnum_nickname = "/^[a-z\d]{1}[a-z\d]*[a-z\d]{1}$/iu";
	
		// Проверка на id
		if (empty($user_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на почту
		if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
			throw new Exception('Вы указали неверный e-mail');
		}
		
		// Проверка на Имя
		if (empty($first_name) or !preg_match($preg, $first_name)) {
			throw new Exception('Вы не указали имя');
		} elseif (mb_strlen($first_name, 'utf-8') < 2) {
			throw new Exception('Имя не может быть менее 2 символов');
		}
		
		// Проверка на Фамилию
		if (empty($last_name) or !preg_match($preg, $last_name)) {
			throw new Exception('Вы не указали фамилию');
		} elseif (mb_strlen($last_name, 'utf-8') < 2) {
			throw new Exception('Фамилия не может быть менее 2 символов');
		}
		
		// Проверка на занятость логин
		if (empty($nickname)) {
			$nickname = NULL;
			$display_name = 0;
		} else {
			
			// Если Nickname меньше 4 символов
			if (mb_strlen($nickname, 'utf-8') < 4 or !preg_match($preg_alnum_nickname, $nickname)) {
				throw new Exception('Никнейм менее 4 символов или в нём имеются запрещенные символы');
			}
			
			// Подготавливаем запрос в базу данных		
			$sth = $dbh->prepare('SELECT * FROM users WHERE id != ? and nickname = ?;');
			$sth->execute(array($user_id, $nickname));

			// Обработка полученных данных
			$result = $sth->fetchAll(PDO::FETCH_ASSOC);
			if (count($result) > 0) {
				throw new Exception('Такой никнейм уже кем-то занят');
			}
			
		}

		// Проверка на изменения пароля
		if (empty($salt)) {
			
			$session_id = null;
			$stmt = $dbh->prepare('UPDATE users SET mail=?, nickname=?, first_name=?, last_name=?, display_name=?, write_to_me=? WHERE id=?;');
			$stmt->execute(array($mail, $nickname, $first_name, $last_name, $display_name, $write_to_me, $user_id));
			
		} else {
			
			// Сразу создаем новый id сессии, что-бы закрыть все другие сеансы.
			$session_id = gen_id();
			$stmt = $dbh->prepare('UPDATE users SET mail=?, nickname=?, password=?, salt=?, first_name=?, last_name=?, display_name=?, write_to_me=?, session_id=? WHERE id=?;');
			$stmt->execute(array($mail, $nickname, $password, $salt, $first_name, $last_name, $display_name, $write_to_me, $session_id, $user_id));
			
		}
		
		// Выводим сообщение
		$message = ['status' => 'success', 'session_id' => $session_id];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	

//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Регистрация пользователя
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'register_user':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$password = $_POST['password'];
		$salt = $_POST['salt'];
		$first_name = $_POST['first_name'];
		$last_name = $_POST['last_name'];
		$mail = $_POST['mail'];
		
		// Проверка строки на фхождения
		$preg = "/^[a-zа-яё]{1}[a-zа-яё]*[a-zа-яё]{1}$/iu";
		$preg_alnum = "/^[a-zа-яё\d]{1}[a-zа-яё\d]*[a-zа-яё\d]{1}$/iu";
		
		// Проверка на Имя
		if (empty($first_name) or !preg_match($preg, $first_name)) {
			throw new Exception('Вы не указали имя');
		} elseif (mb_strlen($first_name, 'utf-8') < 2) {
			throw new Exception('Имя не может быть менее 2 символов');
		}
		
		// Проверка на Фамилию
		if (empty($last_name) or !preg_match($preg, $last_name)) {
			throw new Exception('Вы не указали фамилию');
		} elseif (mb_strlen($last_name, 'utf-8') < 2) {
			throw new Exception('Фамилия не может быть менее 2 символов');
		}

		// Проверка на почту
		if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
			throw new Exception('Вы указали неверный e-mail');
		}

		// Устанавливаем значения
		$avatar_id = gen_id();
		$date_register = date("Y-m-d H:i:s");

		// Подготавливаем запрос в базу данных		
		$sth = $dbh->prepare('SELECT * FROM users WHERE mail = ?;');
		$sth->execute(array($mail));

		// Обработка полученных данных
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($result) > 0) {
			$reg_code = $result[0]['reg_code'];
			$id_user = $result[0]['id'];
			if (empty($reg_code)) {
				throw new Exception('Такой пользователь существует');
			}
		}
		
		// Создаем код подтверждения почты
		$pass = generatePassword();
		
		// Отправляем письмо пользователю
		$subject = "Simple Chat - Подтверждение аккаунта"; 
		$message = ' 
		<html> 
			<head> 
				<title>Код подтверждения почты</title>				
			</head> 
			<body> 
				<p style="font-size: 13px!Important;">
					<b>Код подтверждения аккаунта - Simple Chat</b> <br>
					<span style="font-size: 13px!Important; color: #777;">Введите данный код в окно программы для подтверждения Вашего аккаунта</span>
				</p>
				
				<p style="font-size: 13px!Important;border-color:#e6e6e6;border-width:1px;border-style:solid;background-color:#f5f5f5;padding-top:15px;padding-right:15px;padding-bottom:15px;padding-left:15px;">'.$pass.'</p>
			</body> 
		</html>'; 
		$headers = "Content-type: text/html; charset=utf-8 \r\nFrom: Simple Chat <account@simple-chat.ru>\r\n";
		mail($mail, $subject, $message, $headers); 
		
		// Если запись существует то, обновляем ее
		if (!empty($reg_code)) {
			// Подготавливаем запрос в базу данных
			$stmt = $dbh->prepare('UPDATE users SET password=:password, salt=:salt, first_name=:first_name, last_name=:last_name, reg_code=:reg_code WHERE id=:id;');
			$stmt->execute(array(':password'=>$password, ':salt'=>$salt, ':first_name'=>$first_name, ':last_name'=>$last_name, ':reg_code'=>$pass, ':id'=>$id_user));			
		} else {
			// Подготавливаем запрос в базу данных
			$stmt = $dbh->prepare('INSERT INTO users (mail, password, salt, first_name, last_name, last_login, date_register, avatar, block, reg_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?);');
			$stmt->execute(array($mail, $password, $salt, $first_name, $last_name, $date_register, $date_register, $avatar_id, 1, $pass));
		}
		
		// Создаем картинку отсутствия аватара
		$no_avatar = "../avatars/no_avatar.jpg";
		$avatar = "../avatars/$avatar_id.jpg";
		$avatar_thumb = "../avatars/$avatar_id.thumb.jpg";

		if (!copy($no_avatar, $avatar)) {
			throw new Exception('Возможно не выполнились некоторые действия');
		}
		
		// Создаем thumbnail фотографии для вывода в клиенте
		ImageThumbnail($avatar, $avatar_thumb, 50, "image/jpeg");
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Подтверждаем почту пользователя
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'approve_mail_user':


	// Проверяем данные на соответствие
	try {

		// Устанавливаем значения
		$mail = $_POST['mail'];
		$code = $_POST['code'];
	
		// Проверка на id
		if (empty($mail)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на почту
		if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
			throw new Exception('Вы указали неверный e-mail');
		}

		// Проверка на код авторизации
		if (!empty($code)) {
			
			// Подготавливаем запрос в базу данных		
			$sth = $dbh->prepare('SELECT id, reg_code FROM users WHERE mail = ? AND reg_code = ? LIMIT 1;');
			$sth->execute(array($mail, $code));

			// Обработка полученных данных
			$result = $sth->fetchAll(PDO::FETCH_ASSOC);
			if (count($result) > 0 AND $result[0]['reg_code'] == $code) {
				$stmt = $dbh->prepare('UPDATE users SET block=:block, reg_code=:reg_code WHERE mail=:mail AND reg_code=:code AND id=:id;');
				$stmt->execute(array(':block'=>0, ':reg_code'=>NULL, ':mail'=>$mail, ':code'=>$code, ':id'=>$result[0]['id']));				
			} else {
				throw new Exception('Вы указали неверный код!');
			}
			
		}
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Запрос на добавление в друзья
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'add_user_bookmarks':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$user_two_id = (int) $_POST['user_two_id'];
		$date = date("Y-m-d H:i:s");
		
		// Подготавливаем запрос в базу данных		
		$sth = $dbh->prepare('SELECT user_one_id, user_two_id FROM bookmarks_users WHERE (user_one_id = ? AND user_two_id = ?);');
		$sth->execute(array($user_id, $user_two_id));

		// Обработка полученных данных
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($result) > 0) {
			throw new Exception('Пользователь уже в закладках');
		}

		// Подготавливаем запрос в базу данных
		$stmt = $dbh->prepare('INSERT INTO bookmarks_users (user_one_id, user_two_id, date) VALUES (?, ?, ?)');
		$stmt->execute(array($user_id, $user_two_id, $date));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
		
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Удалить из закладок
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'remove_user_bookmarks':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$bookmark_id = (int) $_POST['bookmark_id'];

		// Обновляем данные в БД
		$stmt = $dbh->prepare('DELETE FROM bookmarks_users WHERE id = ?;');
		$stmt->execute(array($bookmark_id));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}

	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Загрузка аватара
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'upload_avatar':


	// Проверяем данные на соответствие
	try {

		// Устанавливаем значения
		$avatar_id = gen_id();
		$avatar = $_FILES;
		
		// Устанавливаем значения
		$src = "../avatars/$avatar_id.tmp.jpg";
		$src_new = "../avatars/$avatar_id.jpg";
		$src_thumb = "../avatars/$avatar_id.thumb.jpg";
		$mime_type = $avatar['avatar']['type'];
		$name = urldecode($avatar['avatar']['name']);
		$file_ext = pathinfo($name, PATHINFO_EXTENSION);	

		// Проверка на id
		if (empty($user_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверка на файлы
		if (empty($avatar)) {
			throw new Exception('Ошибка передачи данных');
		}		

		// Копируем изображение
		if($avatar['avatar']['error'] == 0) { 
		
			// Получаем MIME TYPE файла
			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			$mtype = finfo_file($finfo, $avatar["avatar"]["tmp_name"]);
			finfo_close($finfo);
			
			// Перемещяем файл в дирректорию
			if (!rename($avatar["avatar"]["tmp_name"], $src)) {
				throw new Exception('Ошибка обработки данных');
			} else {
				
				// Создаем thumbnail фотографии для вывода в клиенте
				// А так же пересоздаем оригинал изображения удаляя при этом все метаданные внутри файла
				$type_img = array('image/jpeg','image/png');
				if (in_array($mtype, $type_img)) {
					
					// Перезаписываем оригинал аватарки
					ImageThumbnail($src, $src_new, 200, $mtype);
					// Создаем миниатюру
					ImageThumbnail($src_new, $src_thumb, 50, 'image/jpeg');
					
					// Удаляем старый файл аватара
					$sth = $dbh->prepare('SELECT avatar FROM users WHERE id = ?');
					$sth->execute(array($user_id));
					
					// Удаляем старые файлы
					$result = $sth->fetchAll(PDO::FETCH_ASSOC);
					$old_avatar = '../avatars/'.$result[0]['avatar'].'.jpg';
					$old_avatar_thumb = '../avatars/'.$result[0]['avatar'].'.thumb.jpg';
					unlink($src);
					unlink($old_avatar);
					unlink($old_avatar_thumb);
					
					// Обновляем данные в БД
					$stmt = $dbh->prepare('UPDATE users SET avatar=:avatar_id WHERE id=:id;');
					$stmt->execute(array(':avatar_id'=>$avatar_id, ':id'=>$user_id));
					
				}
				
			}
		} else { 
			throw new Exception('Ошибка передачи данных');
		}
		
		// Выводим сообщение
		$message = [
					'status' => 'success', 
					'avatar' => $avatar_id
				   ];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Запрос на создание кода сброса пароля аккаунта
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'create_reset_code_account':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$mail = $_POST['mail'];

		// Проверка на сообщение
		if (empty($mail)) {
			throw new Exception('Ошибка передачи данных');
		}

		// Проверяем на возможность существования пользователя в этом диалоге
		$sth = $dbh->prepare('SELECT mail FROM users AS U WHERE U.mail = ?;');
		$sth->execute(array($mail));

		// Выводим сообщение
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($result) == 1) {
			
			// Создаем код сброса пароля
			$reset_code = gen_id();
			$stmt = $dbh->prepare('UPDATE users SET reset_code=:reset_code WHERE mail=:mail;');
			$stmt->execute(array(':reset_code'=>$reset_code, ':mail'=>$mail));
			
			// Отправляем письмо пользователю
			$subject = "Simple Chat - код сброса пароля"; 
			$message = ' 
			<html> 
				<head> 
					<title>Код сброса пароля</title>				
				</head> 
				<body> 
					<p style="font-size: 13px!Important;">
						<b>Код сброса пароля - Simple Chat</b> <br>
						<span style="font-size: 13px!Important; color: #777;">Если Вы не запрашивали восстановление пароля, просто проигнорируйте это письмо.</span>
					</p>
					<p style="font-size: 13px!Important;border-color:#e6e6e6;border-width:1px;border-style:solid;background-color:#f5f5f5;padding-top:15px;padding-right:15px;padding-bottom:15px;padding-left:15px;">'.$reset_code.'</p>
				</body> 
			</html>'; 
			$headers = "Content-type: text/html; charset=utf-8 \r\nFrom: Simple Chat <account@simple-chat.ru>\r\n";
			mail($mail, $subject, $message, $headers); 
			
		} else {
			throw new Exception('Пользователь не найден');
		}
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
		
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Сбрасываем и создаем временный пароль пользователю
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'reset_password_account':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$code = $_POST['code'];
		$mail = $_POST['mail'];
		
		// Проверка на сообщение
		if (empty($code)) {
			throw new Exception('Ошибка передачи данных');
		}

		// Проверка на почту
		if (empty($mail)) {
			throw new Exception('Ошибка передачи данных');
		}

		// Проверяем на возможность существования пользователя в этом диалоге
		$sth = $dbh->prepare('SELECT mail, reset_code FROM users AS U WHERE U.mail = ? AND reset_code = ?;');
		$sth->execute(array($mail, $code));

		// Выводим сообщение
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($result) == 1) {
			
			// Создаем код сброса пароля
			$pass = generatePassword();
			$salt = microtime();
            $hash_pass = hash('sha256', $pass . $salt);
			$stmt = $dbh->prepare('UPDATE users SET password=:password, salt=:salt, reset_code=:set_null WHERE mail=:mail AND reset_code=:reset_code;');
			$stmt->execute(array(':password'=>$hash_pass, ':salt'=>$salt, ':set_null'=>NULL, ':mail'=>$mail, ':reset_code'=>$code));
			
			// Отправляем письмо пользователю
			$subject = "Simple Chat - временный пароль"; 
			$message = ' 
			<html> 
				<head> 
					<title>Временный пароль</title>				
				</head> 
				<body> 
					<p style="font-size: 13px!Important;">
						<b>Временный пароль - Simple Chat</b> <br>
						<span style="font-size: 13px!Important; color: #777;">Не забудьте поменять свой пароль в профиле.</span>
					</p>
					
					<p style="font-size: 13px!Important;border-color:#e6e6e6;border-width:1px;border-style:solid;background-color:#f5f5f5;padding-top:15px;padding-right:15px;padding-bottom:15px;padding-left:15px;">'.$pass.'</p>
				</body> 
			</html>'; 
			$headers = "Content-type: text/html; charset=utf-8 \r\nFrom: Simple Chat <account@simple-chat.ru>\r\n";
			mail($mail, $subject, $message, $headers); 
			
		} else {
			throw new Exception('Пользователь не найден или сброс пароля не запрашивался');
		}
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	
	
//---------------------------------------------------------------------------------------------------------------------------------------------------------------
// Функция жалобы на пользователя
//---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'complaint_user':


	// Проверяем данные на соответствие
	try {
		
		// Устанавливаем значения
		$user_complaint_id = (int) $_POST['user_complaint_id'];
		$date_added = date("Y-m-d H:i:s");
		
		// Проверка на id диалога
		if (empty($user_complaint_id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		// Проверяем на возможность существования такой записи в БД
		$sth = $dbh->prepare('SELECT id FROM сomplaints AS C WHERE C.user_id = ? AND C.user_complaint_id = ?;');
		$sth->execute(array($user_id, $user_complaint_id));

		// Выводим сообщение
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($result) >= 1) {
			throw new Exception('Ваша жалоба уже была отправлена');
		}
			
		// Устанавливаем параметр
		$stmt = $dbh->prepare('INSERT INTO сomplaints (user_id, user_complaint_id, date) VALUES (?, ?, ?)');
		$stmt->execute(array($user_id, $user_complaint_id, $date_added));
		
		// Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message);

	} catch(Exception $e) {

		// Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message);

	}
	

default:
break;


}


	// Закрываем соединение с базой
	$dbh = null;
	die();
	

?>