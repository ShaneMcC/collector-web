<?php
	require_once(dirname(__FILE__) . '/../functions.php');

	header("Access-Control-Allow-Origin: *");
	header("Access-Control-Allow-Headers: *");
	header('Content-Type: application/json; charset=utf-8');

	if (empty($config['influx']['host'])) {
		die(json_encode(['error' => 'No influx host found.']));
	}

	$influxClient = new InfluxDB\Client($config['influx']['host'], $config['influx']['port']);
	$influxDatabase = $influxClient->selectDB($config['influx']['db']);
	if (!$influxDatabase->exists()) {
		die(json_encode(['error' => 'No influx db found.']));
	}

	$dataType = isset($_REQUEST['dt']) ? $_REQUEST['dt'] : '';

	$data = false;

	if ($dataType == 'devices') {
		$query = 'SHOW series';
		$result = $influxDatabase->query($query);
		$data = [];

		foreach ($result->getPoints() as $kv) {
			$bits = explode(',', $kv['key']);
			array_shift($bits);

			$d = [];
			foreach ($bits as $b) {
				$b2 = explode('=', $b, 2);
				$d[$b2[0]] = stripslashes($b2[1]);
			}

			if (!isset($d['serial']) || !isset($d['location']) || !isset($d['type'])) { continue; }

			$location = $d['location'];
			$serial = $d['serial'];
			$type = $d['type'];
			$name = isset($d['name']) ? $d['name'] : $d['serial'];

			if (!isset($data[$location])) { $data[$location] = []; }
			if (!isset($data[$location][$serial])) { $data[$location][$serial] = ['name' => $name, 'types' => []]; }

			$data[$location][$serial]['types'][] = $type;
		}
	} else if ($dataType == 'temp' || $dataType == 'power' || $dataType == 'humidity' || $dataType == 'light') {
		$start = 'now() - 6h';
		$end = 'now()';
		$interval = '60s';
		$modifier = 1000;

		if ($dataType == 'temp') {
			$type = '("type" = \'temp\' OR "type" = \'temp1\')';
		} else if ($dataType == 'power') {
			$type = '("type" = \'REAL_POWER\' OR "type" = \'instantPower\')';
		} else if ($dataType == 'humidity') {
			$type = '("type" = \'humidityrelative\')';
		} else if ($dataType == 'light') {
			$type = '("type" = \'lightlevel\')';
			$modifier = 1;
		}

		$query = <<<QUERY
			SELECT mean("value")/$modifier FROM "value" WHERE $type AND time >= $start and time <= $end GROUP BY time($interval), "name", "location" fill(null)
		QUERY;

		$result = $influxDatabase->query(trim($query));
		$data = $result->getPoints();
	}

	echo die(json_encode($data, JSON_PRETTY_PRINT));
