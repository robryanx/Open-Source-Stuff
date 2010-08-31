<?php

include_once("config.php");
include_once("includes/db_mysqli.php");

$db = new db_mysqli($config);
$created = time();
$username = "rob";
$values = array(&$username, &$created);
$db->prepare_insert_query("user", array("username", "created"), $values, array("s", "i"));



?>