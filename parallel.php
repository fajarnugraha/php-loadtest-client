#!/usr/bin/env php
<?php
error_reporting(E_ALL);
Swoole\Runtime::enableCoroutine();
require_once(__DIR__."/functions.php");

$args = get_args($argc, $argv);
if (!($base_target = get_base_target($args))) {
	echo "Error: invalid url or json file. See '".$args->getCommand()." --help'\n";
	die;
}

$params = [
	"max" => [
		"channel" => 10,
		"column" => 100,
		"target" => $args->getOpt('requests'),
		"thread" => $args->getOpt('threads',1),
		"ramp-up-target" => $args->getOpt('ramp-up-requests',0),
		"ramp-up-thread" => $args->getOpt('ramp-up-threads',1),

	],
	"timeout" => [
		"thread"=>60,
		"channel"=>0.01,
		"request"=>30,
		"connect"=>10,
	],
	"tag-sequence" => $args->getOpt('tag-sequence', 0),
];
//var_dump($params);die();
$outs=[];
$targets = create_targets($base_target, $params["max"]["target"], $params["tag-sequence"]);

if ($params["max"]["ramp-up-target"]) {
	$r_targets = create_targets($base_target, $params["max"]["ramp-up-target"], $params["tag-sequence"]);
	echo "Ramping up ".number_format(count($r_targets))." url(s) in ".number_format($params["max"]["ramp-up-thread"])." thread(s)\n\n";
	Co\run(function() use ($r_targets, &$outs, $params) {
		$cin = new Swoole\Coroutine\Channel(min($params["max"]["channel"], $params["max"]["ramp-up-target"]));
		$cout = new Swoole\Coroutine\Channel(min($params["max"]["channel"], $params["max"]["ramp-up-target"]));

		go(function () use ($cin, $r_targets) {
			foreach ($r_targets as $id => $target) {
				$cin->push([ "id"=>$id, "target"=> $target ]);
			}
		});

		for ($i = 0; $i < $params["max"]["ramp-up-thread"]; $i++) {
			$params["id"] = $i;
			go(function() use ($cin, $cout, $params) {get_targets($cin, $cout, $params);});
		};

		go(function () use ($r_targets, $cout, &$outs, $params) {$outs = get_outputs($r_targets, $cout, $params);});
	});
	echo "\n";
}

$s = microtime(true);
echo "Getting ".number_format(count($targets))." url(s) in ".number_format($params["max"]["thread"])." thread(s)\n\n";
Co\run(function() use ($targets, &$outs, $params) {
	$cin = new Swoole\Coroutine\Channel(min($params["max"]["channel"], $params["max"]["target"]));
	$cout = new Swoole\Coroutine\Channel(min($params["max"]["channel"], $params["max"]["target"]));

	go(function () use ($cin, $targets) {
		foreach ($targets as $id => $target) {
			$cin->push([ "id"=>$id, "target"=> $target ]);
		}
	});

	for ($i = 0; $i < $params["max"]["thread"]; $i++) {
		$params["id"] = $i;
		go(function() use ($cin, $cout, $params) {get_targets($cin, $cout, $params);});
	};

	go(function () use ($targets, $cout, &$outs, $params) {$outs = get_outputs($targets, $cout, $params);});
});
echo "\n";

$ok_index = get_ok_index($outs);
$error_index = get_error_index($outs);
$n = count($error_index);

echo "Completed in ".pretty_number(microtime(true)-$s)."s (excluding ramp-up)";
echo ", ".number_format($n)." (".number_format($n*100/$params["max"]["target"],1)."%) errors";
echo ", took ".memory_get_usage_mb()." memory";
echo "\n\n";

$timing=[];
foreach($outs as $id => $out) {
	if (!$out["result"]["error"]) {
		$info = $out["result"]["info"];
		$timing["namelookup"][$id] = $info["namelookup_time"];
		$timing["redirect"][$id] = $info["redirect_time"];
		$timing["connect"][$id] = $info["connect_time"];
		$timing["pretransfer"][$id] = $info["pretransfer_time"];
		$timing["starttransfer"][$id] = $info["starttransfer_time"];
		$timing["total"][$id] = $info["total_time"];
	}
}

if ($ok_index) {
	echo "Response times (s, non-error only)\n";
	echo "==================================\n";
	foreach ($timing as $key => $arr) {
		echo $key."\t";
		if (strlen($key) < 8) echo "\t";
		echo "\010\010: min ".pretty_number(min($arr))."\tmax ".pretty_number(max($arr))."\tavg ".pretty_number(array_sum($arr)/count($arr))."\n";
	}
	echo "\n";
	echo "Response samples\n";
	echo "================\n";
	print_sample_responses($targets, $outs, $ok_index, $params);
	echo "\n";
}

if ($error_index) {
	$n = count($error_index);
	echo "Error samples\n";
	echo "=============\n";
	print_sample_errors($targets, $outs, $error_index);
}
