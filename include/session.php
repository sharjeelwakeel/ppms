<?php
function userloggedin(){
	if (session_status() === PHP_SESSION_NONE) {
		session_start();
	}
	if (isset($_SESSION['loggedInUser']) && !empty($_SESSION['loggedInUser'])) {
		return true;
	}
	else {
		return false;
	}
}
?>