<?php
	$request_uri = $_SERVER[REQUEST_URI];
	header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found", true, 404);
?><!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">
<html><head>
<title>404 Not Found</title>
</head><body>
<h1>Not Found</h1>
<p>The requested URL <?php echo($request_uri); ?> was not found on this server.</p>
<hr>
<address>404 automatically detected by <a href="https://wordpress.org/plugins/redirect-404/" title="Redirect 404 Errors">Redirect 404 Errors</a> plugin for Wordpress by <a href="http://webd.uk" title="webd.uk">webd.uk</a>. To unblock this page, sign into your control panel and go to "Settings" then "404 Settings".</address>
</body></html><?php
	die();
?>
