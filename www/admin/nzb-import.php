<?php
require_once("config.php");
require_once(WWW_DIR."lib/adminpage.php");
require_once(WWW_DIR."lib/framework/db.php");
require_once(WWW_DIR."lib/binaries.php");
require_once(WWW_DIR."lib/page.php");
require_once(WWW_DIR."lib/namecleaning.php");
require_once(WWW_DIR."lib/site.php");

$db = new DB();
$binaries = new Binaries();
$namecleaning = new nameCleaning();
$s = new Sites();
$site = $s->get();
$crosspostt = (!empty($site->crossposttime)) ? $site->crossposttime : 2;

$page = new Page;

if (empty($argc))
	$page = new AdminPage();

if (!empty($argc))
	if (!isset($argv[1]))
		exit("ERROR: You must supply a path as the first argument.\n");

$filestoprocess = Array();
$browserpostednames = Array();
$viabrowser = false;

if (!empty($argc) || $page->isPostBack() )
{
	$retval = "";

	// Via browser, build an array of all the nzb files uploaded into php /tmp location.
	if (isset($_FILES["uploadedfiles"]))
	{
		foreach ($_FILES["uploadedfiles"]["error"] as $key => $error)
		{
			if ($error == UPLOAD_ERR_OK)
			{
				$tmp_name = $_FILES["uploadedfiles"]["tmp_name"][$key];
				$name = $_FILES["uploadedfiles"]["name"][$key];
				$filestoprocess[] = $tmp_name;
				$browserpostednames[$tmp_name] = $name;
				$viabrowser = true;
			}
		}
	}

	if (!empty($argc))
	{
		$strTerminator = "\n";
		$path = $argv[1];
		$usenzbname = (isset($argv[2]) && $argv[2] == 'true') ? true : false;
	}
	else
	{
		$strTerminator = "<br />";
		$path = (isset($_POST["folder"]) ? $_POST["folder"] : "");
		$usenzbname = (isset($_POST['usefilename']) && $_POST["usefilename"] == 'on') ? true : false;
	}

	if (substr($path, strlen($path) - 1) != '/')
		$path = $path."/";

	$groups = $db->query("SELECT id, name FROM groups");
	foreach ($groups as $group)
		$siteGroups[$group["name"]] = $group["id"];

	if (!isset($groups) || count($groups) == 0)
	{
		if (!empty($argc))
			echo "no groups specified\n";
		else
			$retval.= "no groups specified"."<br />";
	}
	else
	{
		$nzbCount = 0;

		// Read from the path, if no files submitted via the browser.
		if (count($filestoprocess) == 0)
			$filestoprocess = glob($path."*.nzb");
		$start=date('Y-m-d H:i:s');

		foreach($filestoprocess as $nzbFile)
		{
			$nzba = file_get_contents($nzbFile);

			$xml = @simplexml_load_string($nzba);
			if (!$xml || strtolower($xml->getName()) != 'nzb')
				continue;

			$importfailed = $isBlackListed = $skipCheck = false;
			$i = $totalFiles = $totalsize = 0;
			$firstname = $postername = $postdate = array();

			foreach($xml->file as $file)
			{
				// File info.
				$groupID = -1;
				$name = (string)$file->attributes()->subject;
				$firstname[] = $name;
				$fromname = (string)$file->attributes()->poster;
				$postername[] = $fromname;
				$unixdate = (string)$file->attributes()->date;
				$totalFiles++;
				$date = date("Y-m-d H:i:s", (string)$file->attributes()->date);
				$postdate[] = $date;
				$subject = utf8_encode(trim($firstname['0']));

				// Make a fake message object to use to check the blacklist.
				$msg = array("Subject" => $firstname['0'], "From" => $fromname, "Message-ID" => "");

				// If the release is in our DB already then don't bother importing it.
				if ($usenzbname && $skipCheck !== true)
				{
					$usename = str_replace('.nzb', '', ($viabrowser ? $browserpostednames[$nzbFile] : basename($nzbFile)));
					if ($db->dbSystem() == 'mysql')
					{
						$dupeCheckSql = sprintf("SELECT * FROM releases WHERE name = %s AND postdate - INTERVAL %d HOUR <= %s AND postdate + INTERVAL %d HOUR > %s", $db->escapeString($usename), $crosspostt, $db->escapeString($date), $crosspostt, $db->escapeString($date));
						$res = $db->queryOneRow($dupeCheckSql);
						$dupeCheckSql = sprintf("SELECT * FROM releases WHERE name = %s AND postdate - INTERVAL %d HOUR <= %s AND postdate + INTERVAL %d HOUR > %s", $db->escapeString($subject), $crosspostt, $db->escapeString($date), $crosspostt, $db->escapeString($date));
						$res1 = $db->queryOneRow($dupeCheckSql);
					}
					else if ($db->dbSystem() == 'pgsql')
					{
						$dupeCheckSql = sprintf("SELECT * FROM releases WHERE name = %s AND postdate - INTERVAL '%d HOURS' <= %s AND postdate + INTERVAL '%d HOURS' > %s", $db->escapeString($usename), $crosspostt, $db->escapeString($date), $crosspostt, $db->escapeString($date));
						$res = $db->queryOneRow($dupeCheckSql);
						$dupeCheckSql = sprintf("SELECT * FROM releases WHERE name = %s AND postdate - INTERVAL '%d HOURS' <= %s AND postdate + INTERVAL '%d HOURS' > %s", $db->escapeString($subject), $crosspostt, $db->escapeString($date), $crosspostt, $db->escapeString($date));
						$res1 = $db->queryOneRow($dupeCheckSql);
					}

					// Only check one binary per nzb, they should all be in the same release anyway.
					$skipCheck = true;

					// If the release is in the DB already then just skip this whole procedure.
					if ($res !== false || $res1 !== false)
					{
						if (!empty($argc))
						{
							echo ("Skipping ".$usename.", it already exists in your database.\n");
							flush();
						}
						else
							$retval.= "Skipping ".$usename.", it already exists in your database<br />";

						$importfailed = true;
						break;
					}
				}


				if (!$usenzbname && $skipCheck !== true)
				{
					$usename = $db->escapeString($name);
					if ($db->dbSystem() == 'mysql')
						$dupeCheckSql = sprintf("SELECT name FROM releases WHERE name = %s AND postdate - INTERVAL %d HOUR <= %s AND postdate + INTERVAL %d HOUR > %s", $db->escapeString($firstname['0']), $crosspostt, $db->escapeString($date), $crosspostt, $db->escapeString($date));
					else if ($db->dbSystem() == 'pgsql')
						$dupeCheckSql = sprintf("SELECT name FROM releases WHERE name = %s AND postdate - INTERVAL '%d HOURS' <= %s AND postdate + INTERVAL '%d HOURS' > %s", $db->escapeString($firstname['0']), $crosspostt, $db->escapeString($date), $crosspostt, $db->escapeString($date));
					$res = $db->queryOneRow($dupeCheckSql);

					// Only check one binary per nzb, they should all be in the same release anyway.
					$skipCheck = true;

					// If the release is in the DB already then just skip this whole procedure.
					if ($res !== false)
					{
						if (!empty($argc))
						{
							echo "Skipping ".$subject.", it already exists in your database.\n";
							flush();
						}
						else
							$retval.= "Skipping ".$subject.", it already exists in your database<br />";
						$importfailed = true;
						break;
					}
				}

				// Groups.
				$groupArr = array();
				foreach($file->groups->group as $group)
				{
					$group = (string)$group;
					if (array_key_exists($group, $siteGroups))
					{
						$groupID = $siteGroups[$group];
						$groupName = $group;
					}
					$groupArr[] = $group;

					if ($binaries->isBlacklisted($msg, $group))
						$isBlackListed = TRUE;
				}

				if ($groupID != -1 && !$isBlackListed)
				{
					if ($usenzbname)
						$usename = str_replace('.nzb', '', ($viabrowser ? $browserpostednames[$nzbFile] : basename($nzbFile)));
					if (count($file->segments->segment) > 0)
					{
						foreach($file->segments->segment as $segment)
						{
							$size = $segment->attributes()->bytes;
							$totalsize = $totalsize+$size;
						}
					}
				}
				else
				{
					if ($isBlackListed)
						$errorMessage = "Subject is blacklisted: ".$subject;
					else
						$errorMessage = "No group found for ".$name." (one of ".implode(', ', $groupArr)." are missing";

					$importfailed = true;
					if (!empty($argc))
					{
						echo ($errorMessage."\n");
						flush();
					}
					else
						$retval.= $errorMessage."<br />";

					break;
				}
			}

			if (!$importfailed)
			{
				$relguid = sha1(uniqid(true).mt_rand());
				$nzb = new NZB();
				$propername = false;
				// Removes everything after yEnc in subject.
				$subject = utf8_encode(trim(preg_replace('/yEnc.*?$/', 'yEnc', preg_replace('/(\(\d+\/\d+\))*$/', 'yEnc', $firstname['0'])));
				$cleanerName = $namecleaning->releaseCleaner($subject, $groupName);
				if (!is_array($cleanerName))
					$cleanName = $cleanerName;
				else
				{
					$cleanName = $cleanerName['cleansubject'];
					$propername = $cleanerName['properlynamed'];
				}
				try {
					if ($propername === true)
						$relID = $db->queryInsert(sprintf("INSERT INTO releases (name, searchname, totalpart, groupid, adddate, guid, rageid, postdate, fromname, size, passwordstatus, haspreview, categoryid, nfostatus, nzbstatus, relnamestatus) VALUES (%s, %s, %d, %d, NOW(), %s, -1, %s, %s, %s, %d, -1, 7010, -1, 1, 6)", $db->escapeString($subject), $db->escapeString($cleanName), $totalFiles, $groupID, $db->escapeString($relguid), $db->escapeString($postdate['0']), $db->escapeString($postername['0']), $db->escapeString($totalsize), ($page->site->checkpasswordedrar == "1" ? -1 : 0)));
					else
						$relID = $db->queryInsert(sprintf("INSERT INTO releases (name, searchname, totalpart, groupid, adddate, guid, rageid, postdate, fromname, size, passwordstatus, haspreview, categoryid, nfostatus, nzbstatus) VALUES (%s, %s, %d, %d, NOW(), %s, -1, %s, %s, %s, %d, -1, 7010, -1, 1)", $db->escapeString($subject), $db->escapeString($cleanName), $totalFiles, $groupID, $db->escapeString($relguid), $db->escapeString($postdate['0']), $db->escapeString($postername['0']), $db->escapeString($totalsize), ($page->site->checkpasswordedrar == "1" ? -1 : 0)));
				} catch (PDOException $err) {
					if ($this->echooutput)
						echo "\033[01;31m.".$err."\n";
				}

				if(!isset($error) && $relID !== false);
				{
					if($nzb->copyNZBforImport($relguid, $nzba))
					{

						$message = "Imported NZB successfully. Subject: ".$firstname['0']."\n";
						if (!empty($argc))
						{
							echo ($message."\n");
							flush();
						}
						else
							$retval.= $message."<br />";
					}
					else
					{
						$db->queryExec(sprintf("DELETE FROM releases WHERE postdate = %s AND size = %d", $db->escapeString($postdate['0']), $db->escapeString($totalsize)));
						echo "Failed copying NZB, deleting release from DB.\n";
						$importfailed = true;
					}
				}
				$nzbCount++;
				@unlink($nzbFile);
			}
		}
	}
	$seconds = strtotime(date('Y-m-d H:i:s')) - strtotime($start);
	$retval .= 'Processed '.$nzbCount.' nzbs in '.$seconds.' second(s)';

	if (!empty($argc))
	{
		echo $retval."\n";
		die();
	}
	$page->smarty->assign('output', $retval);
}

$page->title = "Import Nzbs";
$page->content = $page->smarty->fetch('nzb-import.tpl');
$page->render();

?>
