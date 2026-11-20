<?php
session_start();
$is_timeout = isset($_GET['timeout']) && $_GET['timeout'] == '1';
session_unset();
session_destroy();
if ($is_timeout) {
    header("Location: login.php?timeout=1");
} else {
    header("Location: login.php");
}
exit();
?>
