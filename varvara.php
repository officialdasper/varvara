<?php

	/**
	 *
	 * @author Изначальный автор неизвестен
	 * @version 1.0
	 *
	 * @author Vadim Isaev <Vadjkee23@gmail.com> / telegram: @dasper
	 * @version 1.2
	 *
	 */

	// Очистка выходного буфера если это необходимо
	while(ob_get_level() > 0) ob_end_clean();


	// Разрешенные IP-адреса
	$Allow_ip = [
		'127.0.0.1',
		'::1', // IPv6 localhost
	];

	// Проверка IP-адреса
	if(!in_array($_SERVER['REMOTE_ADDR'], $Allow_ip, true)) {
		header('HTTP/1.1 404 Not Found', true, 404);
		exit("Ваш текущий IP: " . htmlspecialchars($_SERVER['REMOTE_ADDR'], ENT_QUOTES, 'UTF-8'));
	}

	// Конфигурация
	$mask_files = $_REQUEST['masks'] ?? ['.php', '.htm', '.html', '.tpl', '.txt', '.inc', '.js'];
	$min_search = 2;
	$max_filesize = 500 * 1024;
	$exec_time = $_REQUEST['exectime'] ?? 180;

	if(file_exists($_SERVER['DOCUMENT_ROOT'] . "/bitrix/.settings.php")) {
		define('BX_FILEMAN_URL_ROOT', ((!empty($_SERVER['HTTPS'])) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']);
	}

	$bitrix_db = $_REQUEST['bitrix_db'] ?? 0;
	$database = $_REQUEST['database'] ?? [];

	try {
		if(!$bitrix_db) {
			$database = GetBitrixDatabase();
		}
	} catch(Exception $e) {
		// Обработка ошибки при получении базы данных
	}

	// Функция получения базы данных Bitrix
	function GetBitrixDatabase() {
		$config = $_SERVER['DOCUMENT_ROOT'] . "/bitrix/.settings.php";
		if(file_exists($config)) {
			$ar = include($config);
			if(is_array($ar) && is_array($ar['connections']['value']['default'])) {
				return [
					'host' => $ar['connections']['value']['default']['host'],
					'database' => $ar['connections']['value']['default']['database'],
					'user' => $ar['connections']['value']['default']['login'],
					'pass' => $ar['connections']['value']['default']['password'],
				];
			}
		}
		return [
			'host' => 'localhost',
			'database' => 'database',
			'user' => 'root',
			'pass' => 'mysql',
		];
	}

	session_start();

	ini_set('memory_limit', '128M');
	ini_set('display_errors', '1');
	error_reporting(E_ALL);
	set_time_limit($exec_time);

	header('Content-Type: text/html; charset=utf-8');

	$text = htmlspecialchars($_REQUEST['text'] ?? '', ENT_QUOTES, 'UTF-8');
	$slow = $_REQUEST['slow'] ?? '';
	$enc = $_REQUEST['enc'] ?? '';
	$mask = $_REQUEST['mask'] ?? '';
	$exclude_mask = $_REQUEST['exclude_mask'] ?? '';

	$old_search = $_REQUEST['old_search'] ?? '';
	$dir = $_REQUEST['dir'] ?? '';
	$where = $_REQUEST['where'] ?? '';
	$strip = $_REQUEST['strip'] ?? '1';
	$step = $_REQUEST['step'] ?? '1';

	$skip_dir = isset($_REQUEST['skip_dir']) ? explode(',', $_REQUEST['skip_dir']) : [
		'cache',
		'managed_cache',
		'upload',
		'log',
		'install',
		'wizards',
	];

	$exclude_mask_files = isset($_REQUEST['exclude_mask_files']) ? explode(',', $_REQUEST['exclude_mask_files']) : [
		'*.tmp',
		'*.bak',
		'*.min.js'
	];

	$matches = 0;
	global $matches, $slow, $enc, $mask, $mask_files, $max_filesize, $database, $exclude_mask;

?>

<!doctype html>
<html lang="ru">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta http-equiv="X-UA-Compatible" content="ie=edge">
	<title>VaRVaRa Searcher</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.13.0/css/all.min.css" rel="stylesheet">
	<link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.25.0/themes/prism-okaidia.min.css" rel="stylesheet"/>
	<link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.25.0/plugins/line-numbers/prism-line-numbers.min.css" rel="stylesheet"/>
	<script src="https://code.jquery.com/jquery-3.6.4.min.js" integrity="sha256-oP6HI9z1XaZNBrJURtCoUT5SUnxFr8s3BzRl+cbzUq8=" crossorigin="anonymous"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.25.0/prism.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.25.0/plugins/line-numbers/prism-line-numbers.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.25.0/plugins/autoloader/prism-autoloader.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.25.0/plugins/normalize-whitespace/prism-normalize-whitespace.min.js"></script>
</head>
<body>

<script>
    document.addEventListener('DOMContentLoaded', (event) => {
        Prism.plugins.NormalizeWhitespace.setDefaults({
            'remove-trailing': true,
            'remove-indent': true,
            'left-trim': true,
            'right-trim': true,
        });

        Prism.highlightAll();

        // Добавляем функциональность для кнопок "Copy"
        document.querySelectorAll('.copy-button').forEach(button => {
            button.addEventListener('click', () => {
                const code = button.nextElementSibling.querySelector('code').innerText;
                navigator.clipboard.writeText(code).then(() => {
                    button.textContent = 'Скопировано!';
                    setTimeout(() => {
                        button.textContent = 'Скопировать код';
                    }, 2000);
                }).catch(err => {
                    console.error('Ошибка копии: ', err);
                });
            });
        });

        // Добавляем обработчик события для переключателя "passspoiler"
        document.querySelector('[name="passspoiler"]').addEventListener('change', function() {
            document.getElementById("pass_spoiler").classList.toggle("d-none");
        });

        // Добавляем обработчик события для переключателя "bitrix_db"
        document.querySelector('[name="bitrix_db"]').addEventListener('change', function() {
            document.getElementById("database").classList.toggle("d-none");
        });

        // Добавляем обработчик события для кнопки "submitbtn"
        document.getElementById("submitbtn").addEventListener('click', function() {
            var sp = document.getElementById("loading");
            sp.classList.toggle("d-none");
            document.getElementById("search").submit();
        });

    });
</script>

<style>

    body{
        font-size: .8em;
    }
    #loading {
        padding: 20px;
        text-align: center;
        width: 300px;
        align-items: center;
        display: flex;
        justify-content: space-evenly;
    }

    .title-found {
        color: var(--bs-code-color);
        word-wrap: break-word;
        font-family: var(--bs-font-monospace);
        text-decoration: none;
    }


    .sqldiv {
        font-family: var(--bs-font-monospace);
        direction: ltr;
        unicode-bidi: bidi-override;
    }

    .card {
        box-shadow: 1px 1px 20px -10px
    }

    pre[class*="language-"].line-numbers {
        position: relative;
        padding-left: 3.8em;
        counter-reset: linenumber;
    }

    pre[class*="language-"].line-numbers > code {
        position: relative;
        white-space: pre-wrap;
        word-wrap: break-word;
    }

    .line-numbers .line-numbers-rows {
        position: absolute;
        pointer-events: none;
        top: 0;
        font-size: 100%;
        left: -3.8em;
        width: 3em; /* works for line-numbers below 1000 lines */
        letter-spacing: -1px;
        border-right: 0;
        -webkit-user-select: none;
        -moz-user-select: none;
        -ms-user-select: none;
        user-select: none;
    }

    .line-numbers-rows > span {
        display: block;
        counter-increment: linenumber;
    }

    .line-numbers-rows > span:before {
        content: counter(linenumber);
        color: #999;
        display: block;
        padding-right: 0.8em;
        text-align: right;
    }

    .copy-button {
        position: absolute;
        top: 0;
        right: 0;
        border: none;
        cursor: pointer;
        opacity: 0.8;
        z-index: 1;
        padding: 5px 10px;
        color: #fff;
        font-weight: 700;
        background: transparent;
    }

    .copy-button:hover {
        opacity: 1;
    }
</style>

<div class="container mt-3">
	<div class="justify-content-center searchdiv mb-3 row">
		<div class="col-md-12 bg-white border">
			<form action="" method="get" id="search">
				<div class="input-group mb-2 mt-3">
					<input type="text" name="text" value="<?= $text; ?>" class="form-control" placeholder="What are you like, Master?" aria-label="What are you like, Master?" aria-describedby="button-addon2" size="100">
					<button class="btn btn-outline-primary" type="submit" id="submitbtn">Найти</button>
				</div>

				<div class="col-md-12">
					<div class="row">
						<div class="col-md-6">
							<div class="col-md-6">
								<div class="form-check form-switch">
									<input class="form-check-input" name="enc" value="1" type="checkbox" id="enc" <?= $enc ? "checked" : ""; ?>>
									<label class="form-check-label" for="enc">CP1251</label>
								</div>
							</div>

							<div class="col-md-6">
								<div class="form-check form-switch">
									<input class="form-check-input" name="mask" value="1" type="checkbox" id="maskcheckbox" <?= $mask || !isset($_REQUEST['text']) ? "checked" : ""; ?>>
									<label class="form-check-label" for="maskcheckbox">Маска</label>
								</div>
							</div>

							<div class="col-md-6">
								<div class="form-check form-switch">
									<input class="form-check-input" name="slow" value="1" type="checkbox" <?= $slow ? "checked" : ""; ?> id="slow">
									<label class="form-check-label" for="slow">Размытый поиск (медленно)</label>
								</div>
							</div>

							<div class="col-md-6">
								<div class="form-check form-switch">
									<input class="form-check-input" name="passspoiler" value="1" type="checkbox" id="passspoiler">
									<label class="form-check-label" for="passspoiler">Доп. настройки</label>
								</div>
							</div>
						</div>
						<div class="col-md-6">
							<div class="row">
								<div class="col-md-6 mt-3">
									<div class="form-floating mb-3">
										<input type="text" class="form-control" name="masks" value="<?= htmlspecialchars(implode(',', (array)$mask_files), ENT_QUOTES, 'UTF-8'); ?>">
										<label for="floatingInput">Расширения файлов, в которых искать</label>
									</div>
								</div>

								<div class="col-md-6 mt-3">
									<div class="form-floating mb-3">
										<input type="text" class="form-control" name="exclude_mask_files" value="<?= htmlspecialchars(implode(',', $exclude_mask_files), ENT_QUOTES, 'UTF-8'); ?>">
										<label for="floatingInput">Не учитывать расширения</label>
									</div>
								</div>
							</div>
						</div>


					</div>
				</div>

				<div class="row mt-3 d-none" id="pass_spoiler">
					<div class="col-md-6">
						<div class="form-check form-switch mb-2">
							<input class="form-check-input" name="bitrix_db" value="1" type="checkbox" id="bitrix_db" <?= $bitrix_db ? "checked" : ""; ?>>
							<label class="form-check-label" for="bitrix_db">База данных Bitrix</label>
						</div>

						<div class="form-check form-switch mb-2">
							<input class="form-check-input" name="strip" value="1" type="checkbox" id="strip" <?= $strip ? "checked" : ""; ?>>
							<label class="form-check-label" for="strip">Срезать теги в SQL</label>
						</div>

						<?php
							$label_key_text = [
								'host' => 'Хост',
								'database' => 'База данных',
								'user' => 'Логин',
								'pass' => 'Пароль',
							];
						?>

						<div id="database" class="<?= $bitrix_db ? "" : "d-none"; ?>">
							<?php foreach($database as $key => $value): ?>
								<div class="input-group mb-2">
									<span class="input-group-text" id="inputGroup-<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($label_key_text[$key], ENT_QUOTES, 'UTF-8'); ?></span>
									<input type="text" name="database[<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>]" value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" class="form-control" aria-describedby="inputGroup-<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">
								</div>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="col-md-6">
						<div class="input-group mb-2">
							<span class="input-group-text" id="inputGroup-exectime">Время работы:</span>
							<input type="text" name="exectime" value="<?= htmlspecialchars($exec_time, ENT_QUOTES, 'UTF-8'); ?>" size="1" class="form-control" aria-label="Sizing example input" aria-describedby="inputGroup-exectime">
						</div>
						<div class="input-group mb-2">
							<span class="input-group-text" id="inputGroup-dir">Путь поиска:</span>
							<input type="text" name="dir" size="60" value="<?= htmlspecialchars($dir ?: pathinfo($_SERVER['SCRIPT_FILENAME'], PATHINFO_DIRNAME), ENT_QUOTES, 'UTF-8'); ?>" class="form-control" aria-label="Sizing example input" aria-describedby="inputGroup-dir">
						</div>
						<div class="input-group mb-2">
							<span class="input-group-text" id="inputGroup-skip_dir">Не учитывать папки:</span>
							<input type="text" name="skip_dir" size="60" value="<?= htmlspecialchars(implode(',', $skip_dir), ENT_QUOTES, 'UTF-8'); ?>" class="form-control" aria-describedby="inputGroup-skip_dir">
						</div>
					</div>
				</div>

				<div class="btn-group mb-3 mt-3 justify-content-center d-flex" role="group" aria-label="Basic radio toggle button group">
					<input type="radio" class="btn-check" name="where" value="name" id="btnradio1" autocomplete="off" <?= $where == "name" ? "checked" : ""; ?>>
					<label class="btn btn-outline-primary" for="btnradio1">Имя файла</label>

					<input type="radio" class="btn-check" name="where" value="file" id="btnradio2" autocomplete="off" <?= !isset($_REQUEST['where']) || $where == "file" ? "checked" : ""; ?>>
					<label class="btn btn-outline-primary" for="btnradio2">Внутри файла</label>

					<input type="radio" class="btn-check" name="where" value="sql" id="btnradio3" autocomplete="off" <?= $where == "sql" ? "checked" : ""; ?>>
					<label class="btn btn-outline-primary" for="btnradio3">SQL</label>

					<input type="checkbox" class="btn-check" name="old_search" value="1" id="btnradio4" autocomplete="off" <?= $old_search ? "checked" : ""; ?>>
					<label class="btn btn-outline-primary" for="btnradio4">Старый поиск</label>
				</div>
			</form>
		</div>
	</div>
</div>

<div class="container">
	<div id="found-block" class="row justify-content-center">
		<div id="loading" class="alert alert-primary d-none">
			<div class="spinner-grow text-primary me-1" role="status"></div>
			Подождите, идет поиск...
		</div>
		<?php
			if($step == "result" && isset($_SESSION['echo'])) {
				echo '<script>document.getElementById("loading").remove();</script>';
				$count = count($_SESSION['echo']);
				echo $count ? '<h2 class="text-center mb-5">Найдено в:</h2>' : '<div class="alert alert-danger" role="alert">Поиск ничего не дал.</div>';
				if($count) {
					foreach($_SESSION['echo'] as $echo) {
						$url = defined('BX_FILEMAN_URL_ROOT') ? sprintf("<a href=\"%s/bitrix/admin/fileman_file_edit.php?path=%s&full_src=Y\" target=\"_blank\" class=\"title-found\">%s</a>", BX_FILEMAN_URL_ROOT, $echo['admin-path'], $echo['path']) : sprintf("<div class=\"title-found\">%s<div>", $echo['path']);
						printf("<div class=\"col-md-12 founddiv bg-white border mb-3\"><div class=\"card-title my-2\">%s</div>%s</div>", $url, $echo['result']);
					}
				}
				unset($_SESSION['echo']);
				exit();
			}

			if(strlen($text) >= $min_search) {
				if($step == 1) {
					$_SESSION['files'] = [];
					$_SESSION['echo'] = [];
					$_SESSION['outp'] = -1;
				}
				if($where == "file") {
					if($step == 1 && !$old_search) {
						if(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' || $old_search) {
							$_SESSION['outp'] = -1;
							echo "<script>document.location.href=document.location.href+'&step=2'</script>";
							exit();
						}
						$extension = "";
						if ($mask) {
							$masks = explode(',', (string)$mask_files);
							$extension = '-type f \\(';
							foreach ($masks as $key => &$file) {
								if ($key !== array_key_first($masks)) {
									$extension .= ' -o';
								}
								$extension .= " -name \"*" . $file . "\" ";
							}
							$extension .= '\\)';

							// Добавление логики исключений
							if ($exclude_mask) {
								$exclude_masks = explode(',', (string)$exclude_mask_files);
								$extension .= ' \\( -not';
								foreach ($exclude_masks as $key => &$exclude_file) {
									if ($key !== array_key_first($exclude_masks)) {
										$extension .= ' -a -not';
									}
									$extension .= " -name \"*" . $exclude_file . "\" ";
								}
								$extension .= '\\)';
							}
						}


						$no_dir = '-not \\( ';
						foreach($skip_dir as $key => &$directory) {
							if($key !== array_key_first($skip_dir)) {
								$no_dir .= ' -o';
							}
							$no_dir .= ' -path \'*/' . $directory . '/*\'';
						}
						$no_dir .= ' \\)';
						$find_command = "find " . $dir . " " . $no_dir . " " . $extension . " -exec grep -Hli \"" . $text . "\" {} \\; 2>/dev/null";
						$_SESSION['outp'] = wsoEx($find_command);
						echo "<script>document.location.href=document.location.href+'&step=2'</script>";
						exit();
					}
					$outp = $_SESSION['outp'];
					if($old_search) {
						$outp = -1;
						if($step == 1) {
							echo "<script>document.location.href=document.location.href+'&step=2'</script>";
							exit();
						}
					}
					if($outp != -1) {
						$number_file = 1;
						foreach($outp as $path) {
							if(trim($path) != '') {
								search_in_file($path, $text, $number_file);
								$number_file += 1;
							}
						}
						echo "<script>document.location.href=document.location.href.replace('step=2','step=result');</script>";
						exit();
					}
					else {
						if($step == 2) {
							$_SESSION['files'] = search_files($dir);
							echo "<script>document.location.href=document.location.href.replace('step=2','step=3');</script>";
							exit();
						}
						if($step == 3) {
							if($slow) $text = trimpage($text);
							$number_file = 1;
							foreach($_SESSION['files'] as $path) {
								if(trim($path) != '') {
									search_in_file($path, $text, $number_file);
									$number_file += 1;
								}
							}
							echo "<script>document.location.href=document.location.href.replace('step=3','step=result');</script>";
							exit();
						}
					}
				}
				if($where == "sql") {
					sql_search($text);
					echo "<script>document.location.href=document.location.href+'&step=result'</script>";
					exit();
				}
				if($where == "name") {
					$number_file = 1;
					$_SESSION['files'] = search_files($dir);
					foreach($_SESSION['files'] as $path) {
						if(trim($path) != '') {
							$_SESSION['echo'][$number_file] = [
								'admin-path' => str_replace(dirname($_SERVER['SCRIPT_FILENAME']), '', $path),
								'path' => $path,
								'result' => '',
							];
							$number_file += 1;
						}
					}
					echo "<script>document.location.href=document.location.href+'&step=result'</script>";
					exit();
				}
			}
			elseif(isset($_REQUEST['text'])) {
				echo '<div class="container"><div class="row justify-content-center"><h2 class="text-center mb-5 mt-5">Слишком короткая строка поиска!</h2></div>';
			}

			function wsoEx($in) {
				$out = '';
				if(function_exists('exec')) {
					@exec($in, $out);
				}
				elseif(function_exists('passthru')) {
					ob_start();
					@passthru($in);
					$out = ob_get_clean();
					$out = explode("\n", $out);
				}
				elseif(function_exists('system')) {
					ob_start();
					@system($in);
					$out = ob_get_clean();
				}
				elseif(function_exists('shell_exec')) {
					$out = shell_exec($in);
					$out = explode("\n", $out);
				}
				elseif(is_resource($f = @popen($in, "r"))) {
					$out = "";
					while(!@feof($f)) {
						$out .= fread($f, 1024);
					}
					pclose($f);
					$out = explode("\n", $out);
				}
				else {
					return -1;
				}
				return $out;
			}

			function sql_search($text) {
				global $database, $slow, $strip;
				$link = mysqli_connect($database['host'], $database['user'], $database['pass']) or die(mysqli_error($link));
				if($link) {
					mysqli_set_charset($link, "utf8");
					$result = mysqli_query($link, "SHOW DATABASES");
					$databases = [];
					while($tmp = mysqli_fetch_array($result)) {
						if($tmp[0] != 'information_schema') {
							$databases[] = $tmp[0];
						}
					}
					foreach($databases as $db) {
						mysqli_select_db($link, $db);
						$result = mysqli_query($link, "SHOW TABLES");
						$tables = [];
						while($tmp = mysqli_fetch_array($result)) {
							$tables[] = $tmp[0];
						}
						foreach($tables as $table) {
							$result = mysqli_query($link, "DESCRIBE " . $table);
							$fields = [];
							if($slow) {
								while($tmp = mysqli_fetch_array($result)) {
									$fields[] = $tmp[0];
								}
							}
							else {
								while($tmp = mysqli_fetch_array($result)) {
									if(stripos($tmp[1], "char") !== false || stripos($tmp[1], "text") !== false) {
										$fields[] = $tmp[0];
									}
								}
							}
							$fld = "";
							foreach($fields as $field) {
								$fld .= "`" . $field . "` LIKE '%" . mysqli_real_escape_string($link, $text) . "%' OR ";
							}
							$result = mysqli_query($link, "SELECT * FROM `" . $table . "` WHERE $fld 1=0");
							$results = [];
							while($tmp = mysqli_fetch_assoc($result)) {
								$results[] = $tmp;
							}
							if(count($results) > 0) {
								$_SESSION['files'][] = $table;
								$_SESSION['echo'] .= '<div class="sqldiv">';
								$_SESSION['echo'] .= "<font color='blue'>Founded In: DB(" . htmlspecialchars($db, ENT_QUOTES, 'UTF-8') . ")->Table(" . htmlspecialchars($table, ENT_QUOTES, 'UTF-8') . ")</font><br>";
								$_SESSION['echo'] .= '<table class="table table-bordered"><thead><tr>';
								foreach($results[0] as $f => $k) {
									$_SESSION['echo'] .= "<th>" . htmlspecialchars($f, ENT_QUOTES, 'UTF-8') . "</th>";
								}
								$_SESSION['echo'] .= "</tr></thead><tbody>";
								foreach($results as $result) {
									$_SESSION['echo'] .= '<tr>';
									foreach($result as $f => $k) {
										$_SESSION['echo'] .= "<td>" . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . "</td>";
										$_SESSION['matches']++;
									}
									$_SESSION['echo'] .= "</tr>\n";
								}
								$_SESSION['echo'] .= "</tbody></table></div>";
							}
						}
					}
				}
			}

			function trimpage($page) {
				$page = trim($page);
				$page = str_replace(["\n", "\r"], "", $page);
				$npage = str_replace("  ", " ", $page);
				while($npage != $page) {
					$page = $npage;
					$npage = str_replace("  ", " ", $page);
				}
				return $page;
			}

			function search_in_file($path, $subject, $number_file) {
				if (file_exists($path)) {
					$file = fopen($path, "r");
					$lines = [];
					$line_number = 0;
					$result = "";
					$context_lines = 2; // Количество строк до и после найденной строки

					// Считать весь файл в массив строк
					while (($line = fgets($file)) !== false) {
						$lines[] = $line;
					}
					fclose($file);

					foreach ($lines as $i => $line_all) {
						$line_number++;
						$find_line = stripos($line_all, $subject);
						if ($find_line !== false) {
							$start = max(0, $i - $context_lines);
							$end = min(count($lines) - 1, $i + $context_lines);

							// Собрать нужные строки
							$context = "";
							for ($j = $start; $j <= $end; $j++) {
								$context .= htmlspecialchars($lines[$j], ENT_QUOTES, 'UTF-8');
							}

							$extension = pathinfo($path, PATHINFO_EXTENSION);
							$language = get_language_by_extension($extension);
							$result .= sprintf(
								"<div class=\"sqldiv mb-2\" style=\"position: relative;\">
                        <button class=\"copy-button\">Скопировать код</button>
                        <pre class=\"line-numbers\" data-start=\"%d\"><code class=\"language-%s\">%s</code></pre>
                    </div>",
								$start + 1, // Начальная строка для line-numbers
								htmlspecialchars($language, ENT_QUOTES, 'UTF-8'),
								$context
							);
						}
					}

					if ($result) {
						$_SESSION['echo'][$number_file] = [
							'admin-path' => str_replace(dirname($_SERVER['SCRIPT_FILENAME']), '', $path),
							'path' => $path,
							'result' => $result,
						];
					}
				}
			}

			function get_language_by_extension($extension) {
				$map = [
					'php' => 'php',
					'htm' => 'markup',
					'html' => 'markup',
					'tpl' => 'markup',
					'txt' => 'markup',
					'inc' => 'php',
					'js' => 'javascript',
				];

				return $map[$extension] ?? 'markup';
			}

			function enc_text_to_utf($text) {
				return @iconv("WINDOWS-1251", "UTF-8", $text);
			}

			function search_files($path) {
				global $mask, $mask_files, $max_filesize;
				$result = [];
				if(!is_dir($path)) {
					if($mask) {
						$skip = true;
						foreach(explode(',', (string)$mask_files) as $msk) {
							if(strpos(strtolower($path), strtolower($msk)) !== false) {
								$skip = false;
							}
						}
						if(!$skip && filesize($path) <= $max_filesize) return [$path];
					}
					elseif(filesize($path) <= $max_filesize) {
						return [$path];
					}
				}
				else {
					$dir = dir($path);
					if($dir) while(false !== ($entry = $dir->read())) if($entry != "." && $entry != "..") {
						$entry = search_files($path . '/' . $entry);
						if(is_array($entry)) $result = array_merge($result, $entry);
						else $result[] = $entry;
					}
				}
				return $result;
			}

		?>
	</div>
</div>

</body>
</html>
