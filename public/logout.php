<?php
require_once __DIR__ . '/../src/helpers.php';

unset($_SESSION['user']);
// The kiosk is a desk session, not the next person's: a shared terminal must
// come back to the normal site when someone signs out.
unset($_SESSION['kiosk']);
session_regenerate_id(true);
flash('Signed out.');
header('Location: login.php');
exit;
