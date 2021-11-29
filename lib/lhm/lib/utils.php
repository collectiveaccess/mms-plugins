<?php
	/**
	 * Basic common utility functions
	 */

	# --------------------------------------------------------
	/**
	 * Try to locate and load setup.php bootstrap file. If load fails return false and 
	 * let the caller handle telling the user. 
	 *
	 * @return bool True if setup.php is located and loaded, false if setup.php could not be found.
	 */
	function _caLoadSetupPHP() {
		// Look for environment variable
		$vs_path = getenv("COLLECTIVEACCESS_HOME");
		if (file_exists("{$vs_path}/setup.php")) {
			require_once("{$vs_path}/setup.php");
			return true;
		}

		// Look in current directory and then in parent directories
		$vs_cwd = getcwd();
		$va_cwd = explode("/", $vs_cwd);
		while(sizeof($va_cwd) > 0) {
			if (file_exists("/".join("/", $va_cwd)."/setup.php")) {
				// try to load pre-save paths
				if(($vs_hints = @file_get_contents(join("/", $va_cwd)."/app/tmp/server_config_hints.txt")) && is_array($va_hints = unserialize($vs_hints))) {
					$_SERVER['DOCUMENT_ROOT'] = $va_hints['DOCUMENT_ROOT'];
					$_SERVER['SCRIPT_FILENAME'] = $va_hints['SCRIPT_FILENAME'];
					if (!isset($_SERVER['HTTP_HOST'])) { $_SERVER['HTTP_HOST'] = $va_hints['HTTP_HOST']; }
				} else {
					// Guess paths based upon location of setup.php (*should* work)
					if (!isset($_SERVER['DOCUMENT_ROOT']) && !$_SERVER['DOCUMENT_ROOT']) { $_SERVER['DOCUMENT_ROOT'] = join("/", $va_cwd); }
					if (!isset($_SERVER['SCRIPT_FILENAME']) && !$_SERVER['SCRIPT_FILENAME']) { $_SERVER['SCRIPT_FILENAME'] = join("/", $va_cwd)."/index.php"; }
					if (!isset($_SERVER['HTTP_HOST']) && !$_SERVER['HTTP_HOST']) { $_SERVER['HTTP_HOST'] = 'localhost'; }
					
					print "[\033[1;33mWARNING\033[0m] Guessing base path and hostname because configuration is not available. Loading any CollectiveAccess screen (except for the installer) in a web browser will cache configuration details and resolve this issue.\n\n";
				}
				
				require_once("/".join("/", $va_cwd)."/setup.php");
				return true;
			}
			array_pop($va_cwd);
		}
		
		// Give up and die
		return false;
	}
	# --------------------------------------------------------
