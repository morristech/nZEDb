<?php
require_once(dirname(__FILE__)."/../../../config.php");
require_once(WWW_DIR."lib/backfill.php");
require_once(WWW_DIR."lib/framework/db.php");
require_once(WWW_DIR."lib/page.php");
require_once(WWW_DIR."lib/category.php");
require_once(WWW_DIR."lib/groups.php");

$pieces = explode("                       ", $argv[1]);
$db = new DB();
$page = new Page();
$n = "\n";
$category = new Category();
$groups = new Groups();

if (!preg_match('/^\[\d+\]/', $pieces[1]))
{
	$db->queryExec("UPDATE releases SET reqidstatus = -2 WHERE id = " . $pieces[0]);
	exit(".");
}

$requestIDtmp = explode("]", substr($pieces[1], 1));
$bFound = false;
$newTitle = "";
$updated = 0;

if (count($requestIDtmp) >= 1)
{
	$requestID = (int) $requestIDtmp[0];
	if ($requestID != 0 and $requestID != "")
	{
		$newTitle = getReleaseNameFromRequestID($page->site, $requestID, $pieces[2]);
		if ($newTitle != false && $newTitle != "")
			$bFound = true;
	}
}

if ($bFound === true)
{
	$groupname = $groups->getByNameByID($pieces[2]);
	$determinedcat = $category->determineCategory($newTitle, $groupname);
	$run = $db->prepare(sprintf("UPDATE releases set reqidstatus = 1, relnamestatus = 12, searchname = %s, categoryid = %d where id = %d", $db->escapeString($newTitle), $determinedcat, $pieces[0]));
	$run->execute();
	$newcatname = $category->getNameByID($determinedcat);
	echo	$n.$n."New name:  ".$newTitle.$n.
		"Old name:  ".$pieces[1].$n.
		"New cat:   ".$newcatname.$n.
		"Group:     ".$pieces[2].$n.
		"Method:    "."requestID".$n.
		"ReleaseID: ". $pieces[0].$n;
	$updated++;
}
else
{
	$db->queryExec("UPDATE releases SET reqidstatus = -2 WHERE id = " . $pieces[0]);
	echo ".";
}

function getReleaseNameFromRequestID($site, $requestID, $groupName)
{
	if ($site->request_url == "")
		return "";

	// Build Request URL
	$req_url = str_ireplace("[GROUP_NM]", urlencode($groupName), $site->request_url);
	$req_url = str_ireplace("[REQUEST_ID]", urlencode($requestID), $req_url);

	$xml = simplexml_load_file($req_url);

	if (($xml == false) || (count($xml) == 0))
		return "";

	$request = $xml->request[0];

	return (!isset($request) || !isset($request["name"])) ? "" : $request['name'];
}
