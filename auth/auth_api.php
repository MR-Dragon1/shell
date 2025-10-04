<?php
// auth_api.php

header('Content-Type: application/json');

$valid_user = "mrroger916";
$valid_pass = md5("kopipagi");

echo json_encode([
    "username" => $valid_user,
    "password" => $valid_pass
]);
?>