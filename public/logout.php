<?php
require_once __DIR__ . '/../src/helpers.php';

unset($_SESSION['user']);
session_regenerate_id(true);
flash('Signed out.');
header('Location: login.php');
exit;
