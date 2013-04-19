<?php

# This file is a part of RackTables, a datacenter and server room management
# framework. See accompanying file "COPYING" for the full copyright and
# licensing information.

/*
*
* This file performs RackTables initialisation. After you include it
* from 1st-level page, don't forget to call fixContext(). This is done
* to enable override of of pageno and tabno variables. pageno and tabno
* together participate in forming security context by generating
* related autotags.
*
*/

require_once 'pre-init.php';
require_once 'config.php';
require_once 'functions.php';
require_once 'database.php';
require_once 'auth.php';
require_once 'navigation.php';
require_once 'triggers.php';
require_once 'remote.php';
require_once 'caching.php';
require_once 'slb.php';

// secret.php may be missing, in which case this is a special fatal error
if (! fileSearchExists ($path_to_secret_php))
	throw new RackTablesError
	(
		"Database connection parameters are read from ${path_to_secret_php} file, " .
		"which cannot be found.<br>You probably need to complete the installation " .
		"procedure by following <a href='?module=installer'>this link</a>.",
		RackTablesError::MISCONFIGURED
	);

connectDB();
transformRequestData();
loadConfigDefaults();
$tab['reports']['local'] = getConfigVar ('enterprise');

if (getConfigVar ('DB_VERSION') != CODE_VERSION)
{
	echo '<p align=justify>This Racktables installation seems to be ' .
		'just upgraded to version ' . CODE_VERSION . ', while the '.
		'database version is ' . getConfigVar ('DB_VERSION') . '.<br>No user will be ' .
		'either authenticated or shown any page until the upgrade is ' .
		"finished.<br>Follow <a href='?module=upgrade'>this link</a> and " .
		'authenticate as administrator to finish the upgrade.</p>';
	exit (1);
}

if (!mb_internal_encoding ('UTF-8'))
	throw new RackTablesError ('Failed setting multibyte string encoding to UTF-8', RackTablesError::INTERNAL);

$rackCodeCache = loadScript ('RackCodeCache');
if ($rackCodeCache == NULL or !strlen ($rackCodeCache))
{
	$rackCode = getRackCode (loadScript ('RackCode'));
	saveScript ('RackCodeCache', base64_encode (serialize ($rackCode)));
}
else
{
	$rackCode = unserialize (base64_decode ($rackCodeCache));
	if ($rackCode === FALSE) // invalid cache
	{
		saveScript ('RackCodeCache', '');
		$rackCode = getRackCode (loadScript ('RackCode'));
	}
}

// avoid notices being thrown
date_default_timezone_set (getConfigVar ('DATETIME_ZONE'));

// Depending on the 'result' value the 'load' carries either the
// parse tree or error message. The latter case is a bug, because
// RackCode saving function was supposed to validate its input.
if ($rackCode['result'] != 'ACK')
	throw new RackTablesError ($rackCode['load'], RackTablesError::INTERNAL);
$rackCode = $rackCode['load'];
// Only call buildPredicateTable() once and save the result, because it will remain
// constant during one execution for constraints processing.
$pTable = buildPredicateTable ($rackCode);
// Constraints parse trees aren't cached in the database, so the least to keep
// things running is to maintain application cache for them.
$parseCache = array();
$entityCache = array();
// used by getExplicitTagsOnly()
$tagRelCache = array();

$taglist = getTagList();
$tagtree = treeFromList ($taglist);

$auto_tags = array();
// Initial chain for the current user.
$user_given_tags = array();

// list of regexps used in findAutoTagWarnings to check RackCode.
// add your regexps here to suppress 'Martian autotag' warnings
$user_defined_atags = array();

// This also can be modified in local.php.
$pageheaders = array
(
	100 => "<link rel='ICON' type='image/x-icon' href='?module=chrome&uri=pix/favicon.ico' />",
);
//addCSS ('css/pi.css');

if (!isset ($script_mode) or $script_mode !== TRUE)
{
	// A successful call to authenticate() always generates autotags and somethimes
	// even given/implicit tags. It also sets remote_username and remote_displayname.
	authenticate();
	// Authentication passed.
	// Note that we don't perform autorization here, so each 1st level page
	// has to do it in its way, e.g. by calling authorize() after fixContext().
}
elseif (! isset ($remote_username))
{
	// Some functions require remote_username to be set to something to act correctly,
	// even though they don't use the value itself.
	$admin_account = spotEntity ('user', 1);
	if (isCLIMode() && FALSE !== $env_user = getenv('USER'))
		// use USER env var if we are in CLI mode
		$remote_username = $env_user;
	else
		$remote_username = $admin_account['user_name'];
	unset ($env_user);
	unset ($admin_account);
}

$virtual_obj_types = explode (',', getConfigVar ('VIRTUAL_OBJ_LISTSRC'));

alterConfigWithUserPreferences();
$op = '';

// load additional plugins
ob_start();
foreach (glob("$racktables_plugins_dir/*.php") as $filename)
    require_once $filename;
// display plugins output if it contains something but newlines
$tmp = ob_get_clean();
if ($tmp != '' and ! preg_match ("/^\n+$/D", $tmp))
	echo $tmp;
unset ($tmp);

// These will be filled in by fixContext()
$expl_tags = array();
$impl_tags = array();
// Initial chain for the current target.
$target_given_tags = array();

callHook ('initFinished');

?>
