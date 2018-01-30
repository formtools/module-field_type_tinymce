<?php

require_once("../../global/library.php");

use FormTools\Modules;

$module = Modules::initModulePage("admin");

$success = true;
$message = "";
if (isset($_POST["reset"])) {
    list($success, $message) = $module->resetFields();
}

$module->displayPage("templates/reset.tpl");
