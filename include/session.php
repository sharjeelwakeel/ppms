<?php
function userloggedin(){
	session_start();
	if (isset($_SESSION['loggedInUser']) && !empty($_SESSION['loggedInUser'])) {
		return true;
	}
	else {
		return false;
	}
}
?>