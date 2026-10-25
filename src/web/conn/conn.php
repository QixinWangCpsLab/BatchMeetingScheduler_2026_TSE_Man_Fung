<?php
$db_hostname = getenv("DB_HOST") ?: "localhost";
$db_port = (int) (getenv("DB_PORT") ?: 3306);
$db_username = getenv("DB_USER") ?: "root";
$db_password = getenv("DB_PASS") ?: "";
$db_name = getenv("DB_NAME") ?: "testwsqlnew";
$conn = new mysqli($db_hostname, $db_username, $db_password, $db_name, $db_port);
$conn->set_charset("utf8mb4");
