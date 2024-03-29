<?php

	function getEnvOrDefault($var, $default) {
		$result = getEnv($var);
		return $result === FALSE ? $default : $result;
	}

	require_once(dirname(__FILE__) . '/config.php');
	require_once(__DIR__ . '/vendor/autoload.php');

	/**
	 * Execute RRD Tool with the given data.
	 *
	 * @param $data Data to pass to rrdtool. (Array will be joined with ' ')
	 * @return array with stdout and stderr keys or FALSE on error.
	 */
	function execRRDTool($data) {
		global $config;

		if (is_array($data)) { $data = implode(' ', $data); }
		if (empty($config['rrdtool']) || !file_exists($config['rrdtool']) || empty($data)) { return FALSE; }

		$descriptorspec = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
		$process = proc_open($config['rrdtool'] . ' - ', $descriptorspec, $pipes);
		fwrite($pipes[0], $data);
		fclose($pipes[0]);

		stream_set_timeout($pipes[1], 1);
		$stdout = stream_get_contents($pipes[1]);

		stream_set_timeout($pipes[2], 1);
		$stderr = stream_get_contents($pipes[2]);

		fclose($pipes[1]);
		fclose($pipes[2]);

		$status = proc_get_status($process);
		proc_close($process);

		return array('stdout' => $stdout, 'stderr' => $stderr, 'status' => $status);
	}

	/**
	 * Check is a string stats with another.
	 *
	 * @param $haystack Where to look
	 * @param $needle What to look for
	 * @return True if $haystack starts with $needle
	 */
	function startsWith($haystack, $needle) {
		$length = strlen($needle);
		return (substr($haystack, 0, $length) === $needle);
	}
