<?php

	/** Where to store data. */
	$config['data'] = __DIR__ . '/data/';

	/** Path to rrdtool binary. */
	$config['rrdtool'] = '/usr/bin/rrdtool';


	/**
	 * influxdb database details.
	 *
	 * If Host is blank, no data will be sent to influx, otherwise a copy of
	 * all data will go to influx as well as RRD.
	 */
	$config['influx']['host'] = getEnvOrDefault('INFLUX_HOST', '');
	$config['influx']['port'] = getEnvOrDefault('INFLUX_PORT', '8086');
	$config['influx']['user'] = getEnvOrDefault('INFLUX_USER', '');
	$config['influx']['pass'] = getEnvOrDefault('INFLUX_PASS', '');
	$config['influx']['db'] = getEnvOrDefault('INFLUX_DB', 'collector-web');

	/** Detailed errors in submit? */
	$config['collector']['rrd']['detailedErrors'] = false;

	/** Valid datatypes to accept. */
	/** Either an array of types to accept, or true to accept everything as GAUGE */
	$config['collector']['datatypes'] = [];

	/** Power data from wemo probe. */
	$config['collector']['datatypes']['instantPower'] = ['rrd' => ['type' => 'GAUGE'], 'type' => 'power'];
	$config['collector']['datatypes']['REAL_POWER'] = ['rrd' => ['type' => 'GAUGE'], 'type' => 'power'];

	/** Temp Data from DS18B20. */
	$config['collector']['datatypes']['temp1'] = ['rrd' => ['type' => 'GAUGE'], 'type' => 'temperature'];

	/** Humidity Data from DHT11. */
	$config['collector']['datatypes']['humidityrelative'] = ['rrd' => ['type' => 'GAUGE'], 'type' => 'temperature'];

	/** Temp Data from DHT11. */
	$config['collector']['datatypes']['temp'] = ['rrd' => ['type' => 'GAUGE'], 'type' => 'temperature'];


	$config['collector']['probes'] = [];
	// $config['collector']['probes']['Home'] = 'SomePassword';

	if (file_exists(dirname(__FILE__) . '/config.local.php')) {
		require_once(dirname(__FILE__) . '/config.local.php');
	}
