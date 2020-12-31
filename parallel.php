#!/usr/bin/env php
<?php
if ($argc < 4) {
	echo "Usage: ".$argv[0]." base_url number_of_urls number_of_parallel threads\n";
	echo "\n";
	echo "Example: ".$argv[0]." 'http://localhost?q=X' 200 100\n";
	echo "url sequence number will be added to query string (e.g. 'http://localhost?q=X&i=11')\n";
	die;
}

$base_url=$argv[1];
$params = [
	"max" => [
		"thread" => $argv[3],
		"channel" => 10,
		"url" => $argv[2],
		"column" => 100,
	],
	"timeout" => [
		"thread"=>60,
		"channel"=>0.01,
		"request"=>60,
		"connect"=>10,
	],
];

require_once(__DIR__."/vendor/autoload.php");
use MiscHelper\Curl;
use MiscHelper\ProgressBar;

function memory_get_usage_mb() {
	return (number_format(memory_get_usage()/(1024**2), 1)."MB");
}

function get_url($cin, $cout, $params) {
	$tid = $params["id"];
	$cid = Swoole\Coroutine::getuid();

	while (($in = $cin->pop($params["timeout"]["channel"])) !== false) {
		$target['url'] = $in["url"];

		$c = new Curl();
		$response = $c->request($target, ['timeout' => 
			['connect' => $params['timeout']['connect'], 'request' => $params['timeout']['request']]
		]);
		unset($c);

		$cout->push(["tid"=>$tid, "cid"=> $cid, "url_id"=>$in["id"], "url"=>$url,
			"result"=>[
				"error" => $response['error'],
				"info" => $response['info'],
				"headers" => $response['headers'],
				"body_size" => $response['length'],
				"body_sample" => $response['sample'],
				#"body" => $response['body'],
			]
		]);
		unset($response);
	}
};

$results = [];

for ($i = 0; $i < $params["max"]["url"]; $i++) {
	$urls[$i] = $base_url."&i=$i";
}

echo "Getting ".number_format(count($urls))." url(s) in ".number_format($params["max"]["thread"])." thread(s)";
if ($params["max"]["url"] >= $params["max"]["column"]) echo "\n";
$s = microtime(true);

Swoole\Runtime::enableCoroutine();
$outs=[];
# reset outputs
$results=[];

echo "\n";
Co\run(function() use ($urls, &$outs, $params) {
	$cin = new Swoole\Coroutine\Channel(min($params["max"]["channel"], $params["max"]["url"]));
	$cout = new Swoole\Coroutine\Channel(min($params["max"]["channel"], $params["max"]["url"]));

	go(function () use ($cin, $urls) {
		foreach ($urls as $id => $url) {
			$cin->push([ "id"=>$id, "url"=> $url ]);
		}
	});

	for ($i = 0; $i < $params["max"]["thread"]; $i++) {
		$params["id"] = $i;
		go(function() use ($cin, $cout, $params) {get_url($cin, $cout, $params);});
	};

	go(function () use ($cin, $cout, $urls, &$outs, $params) {
		$p = new ProgressBar();
		foreach ($urls as $dummy) {
			$data=$cout->pop($params["timeout"]["thread"]);
			if ($data === false) break;
			$outs[$data["url_id"]] = $data;
			if ($data["result"]["error"]) $p->print(ProgressBar::TYPE_ERROR);
			else $p->print(ProgressBar::TYPE_OK);
		}
	});
});
echo "\n";

echo "took ".number_format((microtime(true)-$s),3)."s, ".memory_get_usage_mb()." mem\n";
echo "\n";

$urls_max_index=count($urls)-1;
echo "Response samples:\n";
$samples_index=[0];
for ($i=0; $i < min(2,$urls_max_index-1); $i++) {
	while (array_search($index=rand(1, $urls_max_index-1), $samples_index));
	$samples_index[]=$index;
}
if ($urls_max_index) $samples_index[]=$urls_max_index;
asort($samples_index);
foreach($samples_index as $dummy=>$id) {
	echo "[url #".$outs[$id]["url_id"]."] [".$urls[$id]."] =>";
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
foreach($urls as $id=>$dummy) {
	if ($outs[$id]["result"]["error"]) {
		$errors_index[]=$id;
		$i++;
	}
}
if ($errors_index) {
	echo number_format($i)." errors (".number_format($i*100/$params["max"]["url"],1)."%). Error samples:\n";
	$samples_index=[$errors_index[0]];
	$errors_max_index=$i-1;
	for ($i=0; $i < min(2,$errors_max_index-1); $i++) {
		while (array_search($index=rand(1, $errors_max_index-1), $samples_index));
		$samples_index[]=$errors_index[$index];
	}
	if ($errors_max_index) $samples_index[]=$errors_index[$errors_max_index];
	asort($samples_index);
	foreach($samples_index as $dummy=>$id) {
		echo "[url #".$outs[$id]["url_id"]."] [".$urls[$id]."] => [".$outs[$id]["result"]["error"]."]\n";
	}
}
