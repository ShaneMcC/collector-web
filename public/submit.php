<?php
	require_once(dirname(__FILE__) . '/../functions.php');

	// Check for authentication.
	//
	// Username == location
	// Password == submission key
	if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
		$location = $_SERVER['PHP_AUTH_USER'];
		$key = $_SERVER['PHP_AUTH_PW'];

		if (!isset($config['collector']['probes'][$location]) || $config['collector']['probes'][$location] != $key) {
			unset($_SERVER['PHP_AUTH_USER']);
			unset($_SERVER['PHP_AUTH_PW']);
		}
	}

	// If no valid authentication, then abort.
	if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
		header('WWW-Authenticate: Basic realm="Collector"');
		header('HTTP/1.0 401 Unauthorized');

		die(json_encode(array('error' => 'Unauthorized')));
	}

	// Check that we have RRD Tool.
	$hasRRD = false;
	if (!empty($config['rrdtool'])) {
		if (file_exists($config['rrdtool'])) {
			$hasRRD = true;
		} else {
			die(json_encode(array('error' => 'Internal Error')));
		}
	}

	// Check if we have Influx
	if (!empty($config['influx']['host'])) {
		$influxClient = new InfluxDB\Client($config['influx']['host'], $config['influx']['port'], $config['influx']['user'], $config['influx']['pass']);
		$influxDatabase = $influxClient->selectDB($config['influx']['db']);
		if (!$influxDatabase->exists()) { $influxDatabase->create(); }
	} else {
		$influxClient = NULL;
		$influxDatabase = NULL;
	}


	$postdata = file_get_contents("php://input");
	$indata = @json_decode($postdata, true);
	if ($indata === null) { die(json_encode(array('error' => 'Invalid Data'))); }

	if (isset($indata['time'])) {
		// Non bulk, convert to bulk format
		$indata = [$indata];
	}

	foreach ($indata as $data) {
		$data['location'] = preg_replace('#[^a-z0-9-_ ]#', '', strtolower($location));

		foreach ($data['devices'] as $dev) {
			$dev['serial'] = preg_replace('#[^A-Z0-9-_ ]#', '', strtoupper($dev['serial']));

			if ($hasRRD) {
				$rrdDir = $config['data'] . '/rrd/' . $data['location'] . '/' . $dev['serial'];
				if (!file_exists($rrdDir)) { mkdir($rrdDir, 0755, true); }
				if (!file_exists($rrdDir)) { die(json_encode(array('error' => 'Internal Error'))); }

				$meta = $dev;
				unset($meta['data']);
				@file_put_contents($rrdDir . '/meta.js', json_encode($meta));
			}

			foreach ($dev['data'] as $dataPoint => $dataValue) {
				if (is_array($config['collector']['datatypes'])) {
					if (!isset($config['collector']['datatypes'][$dataPoint])) { continue; }
					$conf = $config['collector']['datatypes'][$dataPoint];
				} else if ($config['collector']['datatypes'] === true) {
					$conf = ['rrd' => ['type' => 'GAUGE'], 'type' => 'any'];
				} else {
					continue;
				}

				$dsname = $dataPoint;
				// Assume Gauge unless otherwise specified.
				$dstype = isset($conf['rrd']['type']) ? $conf['rrd']['type'] : 'GAUGE';

				$storeValue = $dataValue;

				if ($hasRRD) {
					$rrdDataFile = $rrdDir . '/' . $dsname . '.rrd';

					if (!file_exists($rrdDataFile)) { createRRD($rrdDataFile, $dsname, $dstype, $data['time']); }
					if (!file_exists($rrdDataFile)) { die(json_encode(array('error' => 'Internal Error'))); }
				}

				if ($influxClient != null) {
					$tags = ['type' => $dsname, 'location' => $data['location'], 'serial' => $dev['serial'], 'name' => $dev['name']];
					if (isset($dev['tags'])) {
						foreach ($dev['tags'] as $k => $v) {
							if (!isset($tags[$k])) {
								$tags[$k] = (string)$v;
							}
						}
					}

					$point = new InfluxDB\Point('value',
					                            (int)$storeValue,
					                            $tags,
					                            [],
					                            $data['time']
					                           );
					if (!$influxDatabase->writePoints([$point], InfluxDB\Database::PRECISION_SECONDS)) {
						die(json_encode(array('error' => 'Internal Error')));
					}
				}

				if ($hasRRD) {
					$result = updateRRD($rrdDataFile, $dsname, $data['time'], $storeValue);
					if (startsWith($result['stdout'], 'ERROR:')) {
						// Strip path from the error along with new line
						$errorNoPath = substr($result['stdout'], strrpos($result['stdout'], ":") + 2, -1);

						// Check if the error is to do with illegal timestamp
						if ($config['collector']['rrd']['detailedErrors'] || startsWith($errorNoPath, "illegal attempt to update using time")) {
							die(json_encode(array('error' => $errorNoPath)));
						} else {
							die(json_encode(array('error' => 'Internal Error')));
						}
					}
				}
			}
		}
	}

	/**
	 * Create RRD file.
	 * Based on https://www.chameth.com/2016/05/02/monitoring-power-with-wemo.html
	 *
	 * @param $filename Filename to create RRD
	 * @param $dsname Name for the data source
	 * @param $dstype Type for the data source
	 * @param $startTime Start time for the RRD File
	 * @return Output from RRD Command
	 */
	function createRRD($filename, $dsname, $dstype, $startTime) {
		$rrdData = [];
		$rrdData[] = 'create "' . $filename . '"';
		$rrdData[] = '--start ' . $startTime;
		$rrdData[] = '--step 60';
		$rrdData[] = 'DS:' . $dsname . ':' . $dstype . ':120:U:U';
		$rrdData[] = 'RRA:AVERAGE:0.5:1:1440';
		$rrdData[] = 'RRA:AVERAGE:0.5:10:1008';
		$rrdData[] = 'RRA:AVERAGE:0.5:30:1488';
		$rrdData[] = 'RRA:AVERAGE:0.5:120:1488';
		$rrdData[] = 'RRA:AVERAGE:0.5:360:1488';
		$rrdData[] = 'RRA:AVERAGE:0.5:1440:36500';

		return execRRDTool($rrdData);
	}

	/**
	 * Update the RRD File with data.
	 *
	 * @param $filename Filename to update
	 * @param $dsname Name for the data source to update
	 * @param $time Time the value was taken at
	 * @param $value Value to use for update.
	 * @return Output from RRD Command
	 */
	function updateRRD($filename, $dsname, $time, $value) {
		$rrdData = [];
		$rrdData[] = 'update "' . $filename . '"';
		// $rrdData[] = '--skip-past-updates';
		$rrdData[] = '--template ' . $dsname;
		$rrdData[] = $time . ':' . $value;

		return execRRDTool($rrdData);
	}

	die(json_encode(array('success' => 'ok')));
