#!/usr/bin/env php
<?php
error_reporting(E_ALL);
if ($argc < 4) {
	echo "Usage: ".$argv[0]." base_url|url_file number_of_urls number_of_parallel threads [tag_sequence 0|1]\n";
	echo "\n";
	echo "Example #1: ".$argv[0]." 'http://localhost?q=X' 200 100\n";
	echo "urls 'http://localhost?q=X' will be called 200 times, 100 parallel request\n";
	echo "\n";
	echo "Example #2: ".$argv[0]." 'http://localhost?q=X' 200 100 1\n";
	echo "sames as #1, but url sequence number will be added to query string\n";
	echo "(e.g. 'http://localhost?q=X&i=11')\n";
	echo "\n";
	echo "Example #3: ".$argv[0]." url.json 200 100 1\n";
	echo "if first argument does not start with 'http', it will be interpreted as file name\n";
	echo "containing json text defining base url. e.g. if url.txt contains\n";
	echo '{"url":"http:\/\/localhost?q=X","post":{"a":"1","b":"2"},"header":{"User-Agent":"dummy"}}';
	echo "\nbase_url will be 'http://localhost?q=X', request type POST with post variables\n";
	echo "'a=1' and 'b=2', and header 'User-Agent: dummy'\n";
	die;
}

$base_url=$argv[1];
if (substr($base_url, 0, 4) == "http") {
	$base_target = ["url" => $base_url];
} else $base_target=json_decode(file_get_contents($base_url), true);
if (substr($base_target["url"], 0, 4) != "http") {
	echo "Error: invalid base_url or json file\n";
	die;
}

$params = [
	"max" => [
		"thread" => $argv[3],
		"channel" => 10,
		"target" => $argv[2],
		"column" => 100,
	],
	"timeout" => [
		"thread"=>60,
		"channel"=>0.01,
		"request"=>60,
		"connect"=>10,
	],
	"tag_sequence" => (@$argv[4] ? $argv[4] : 0),
];

require_once(__DIR__."/vendor/autoload.php");
use MiscHelper\Curl;
use MiscHelper\ProgressBar;

function memory_get_usage_mb() {
	return (number_format(memory_get_usage()/(1024**2), 1)."MB");
}
function pretty_number($number,$decimals=3) {
	return number_format($number, $decimals);
}

function get_url($cin, $cout, $params) {
	$tid = $params["id"];
	$cid = Swoole\Coroutine::getuid();

	while (($in = $cin->pop($params["timeout"]["channel"])) !== false) {
		$target = $in["target"];
		$c = new Curl();
		$response = $c->request($target, ['timeout' => 
			['connect' => $params['timeout']['connect'], 'request' => $params['timeout']['request']]
		]);
		unset($c);

		$cout->push(["tid"=>$tid, "cid"=> $cid, "url_id"=>$in["id"],
			"result"=>[
				"error" => $response['error'],
				"info" => $response['info'],
				"header" => $response['header'],
				"body_size" => $response['length'],
				"body_sample" => $response['sample'],
				#"body" => $response['body'],
			]
		]);
		unset($response);
	}
};

for ($i = 0; $i < $params["max"]["target"]; $i++) {
	$targets[$i] = $base_target;
	if ($params['tag_sequence']) {
		if (strpos($targets[$i]["url"], "?") === false) $targets[$i]["url"] .= "?";
		else $targets[$i]["url"] .= "&";
		$targets[$i]["url"] .= "i=$i";
	}
}
$outs=[];

echo "Getting ".number_format(count($targets))." url(s) in ".number_format($params["max"]["thread"])." thread(s)\n\n";
$s = microtime(true);

Swoole\Runtime::enableCoroutine();

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
		go(function() use ($cin, $cout, $params) {get_url($cin, $cout, $params);});
	};

	go(function () use ($cin, $cout, $targets, &$outs, $params) {
		$p = new ProgressBar();
		foreach ($targets as $dummy) {
			$data=$cout->pop($params["timeout"]["thread"]);
			if ($data === false) break;
			$outs[$data["url_id"]] = $data;
			if ($data["result"]["error"]) $p->print(ProgressBar::TYPE_ERROR);
			else $p->print(ProgressBar::TYPE_OK);
		}
	});
});
echo "\n";

echo "Completed in ".pretty_number(microtime(true)-$s)."s, took ".memory_get_usage_mb()." memory\n";
echo "\n";

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

echo "Response times (s, non-error only)\n";
echo "==========================\n";
foreach ($timing as $key => $arr) {
	echo $key."\t";
	if (strlen($key) < 8) echo "\t";
	echo "\010\010: min ".pretty_number(min($arr))."\tmax ".pretty_number(max($arr))."\tavg ".pretty_number(array_sum($arr)/count($arr))."\n";
}
echo "\n";

$targets_max_index=count($targets)-1;
echo "Response samples\n";
echo "================\n";
$samples_index=[0];
for ($i=0; $i < min(2,$targets_max_index-1); $i++) {
	while (array_search($index=rand(1, $targets_max_index-1), $samples_index));
	$samples_index[]=$index;
}
if ($targets_max_index) $samples_index[]=$targets_max_index;
asort($samples_index);
foreach($samples_index as $dummy=>$id) {
	echo "[url #".$id."] [".$targets[$id]["url"]."] =>";
	echo "\n\t[thread #".$outs[$id]["tid"]."] [HTTP ".(($code = $outs[$id]["result"]["info"]["http_code"]) ? $code : "error")."] [".number_format($outs[$id]["result"]["body_size"])." bytes]";
	echo "\n\t";
	if ($outs[$id]["result"]["body_sample"]) echo "[".
		((strlen($outs[$id]["result"]["body_sample"]) > $params["max"]["column"]-2) ?
			(substr($outs[$id]["result"]["body_sample"],0,$params["max"]["column"]-13)."...") : $outs[$id]["result"]["body_sample"]). 
		"]";
	else echo "[".$outs[$id]["result"]["error"]."]";
	echo "\n";
}
echo "\n";

$i=0; $errors_index=[];
foreach($targets as $id=>$dummy) {
	if ($outs[$id]["result"]["error"]) {
		$errors_index[]=$id;
		$i++;
	}
}
if ($errors_index) {
	echo number_format($i)." errors (".number_format($i*100/$params["max"]["target"],1)."%).\n";
	echo "Error samples\n";
	echo "=============\n";
	$samples_index=[$errors_index[0]];
	$errors_max_index=$i-1;
	for ($i=0; $i < min(2,$errors_max_index-1); $i++) {
		while (array_search($index=rand(1, $errors_max_index-1), $samples_index));
		$samples_index[]=$errors_index[$index];
	}
	if ($errors_max_index) $samples_index[]=$errors_index[$errors_max_index];
	asort($samples_index);
	foreach($samples_index as $dummy=>$id) {
		echo "[url #".$id."] [".$targets[$id]["url"]."] => [".$outs[$id]["result"]["error"]."]\n";
	}
}
