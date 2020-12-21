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
	],
	"timeout" => [
		"thread"=>60,
		"channel"=>0.01,
		"url"=>60,
		"connect"=>30,
	],
	"counter" => [
		"checkpoint" => 100,
	],
];

function memory_get_usage_mb() {
	return (number_format(memory_get_usage()/(1024**2), 1)."MB");
}

function curl_get_output_header($curl, $header, &$output_headers) {
    $len = strlen($header);
    $header = explode(':', $header, 2);
    if (count($header) < 2) // ignore invalid headers
      return $len;
    $output_headers[strtolower(trim($header[0]))][] = trim($header[1]);
    return $len;
}

function get_url($cin, $cout, $params) {
	$tid = $params["id"];
	$cid = Swoole\Coroutine::getuid();
	$timeout = $params["timeout"];
	while (($in = $cin->pop($params["timeout"]["channel"])) !== false) {
		$url = $in["url"];
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $params["timeout"]["connect"]);
		curl_setopt($ch, CURLOPT_TIMEOUT, $params["timeout"]["url"]);

		$headers = array();
		$headers[] = 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/80.0.3987.163 Safari/537.36';
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLINFO_HEADER_OUT, true);

		$output_headers = [];
		curl_setopt($ch, CURLOPT_HEADERFUNCTION,
		  function($curl, $header) use (&$output_headers) {curl_get_output_header($curl, $header, $output_headers);}
		);

		$body = curl_exec($ch);
		$info = curl_getinfo($ch);
		if (($body === FALSE) || (!$info["http_code"]) || ($info["http_code"] >= 400)) {
		    $error = 'http code "'.$info["http_code"].'", curl error "'.curl_error($ch).'"';
		} else {
			$error = false;
		}
		curl_close($ch);

		$cout->push(["tid"=>$tid, "cid"=> $cid, "url_id"=>$in["id"], "url"=>$url,
			"result"=>[
				"error" => $error,
				"info" => &$info,
				"headers" => $output_headers,
				"body" => &$body,
			]
		]);
	}
};

$results = [];
echo "Starting memory usage: ".memory_get_usage_mb()."\n";

for ($i = 0; $i < $params["max"]["url"]; $i++) {
	$urls[$i] = $base_url."&i=$i";
}

echo "Getting ".number_format(count($urls))." urls in ".number_format($params["max"]["thread"])." parallel threads";
if ($params["max"]["url"] >= $params["counter"]["checkpoint"]) echo "\n";
$s = microtime(true);

Swoole\Runtime::enableCoroutine();
$outs=[];
# reset outputs
$results=[];

echo "\n";
Co\run(function() use (&$urls, &$outs, $params) {
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
		$line_length = 100;
		echo "\n";

		$i=0; $error_detail=0; $pos_detail=0; $pos_summary=0;
		foreach ($urls as $dummy) {
			$data=$cout->pop($params["timeout"]["thread"]);
			if ($data === false) break;
			$outs[$data["url_id"]] = $data;
			if ($data["result"]["error"]) {
				echo "!";
				$error_detail=1;
			} else {
				echo "-";
			}
			$pos_detail++;
			if (++$i % $params["counter"]["checkpoint"] === 0) {
				echo "\r";
				for ($j=0; $j<$line_length; $j++) {
					echo " ";
				}
				echo "\033[F";
				if ($pos_summary) echo "\033[".$pos_summary."C";
				if ($error_detail) echo "!";
				else echo ".";
				echo " #$i\n";
				$error_detail=0; $pos_detail=0; $pos_summary++;
			}
		}
	});
});
echo "\n";

echo "took ".(microtime(true)-$s)."s\n";
echo "memory usage: ".memory_get_usage_mb()."\n";
echo "\n";

$urls_max_index=count($urls)-1;
echo "response samples:\n";
$samples_index[]=0;
for ($i=0; $i < min(8,$urls_max_index-2); $i++) $samples_index[]=rand(0, $urls_max_index);
if ($urls_max_index) $samples_index[]=$urls_max_index;
foreach($samples_index as $dummy=>$id) {
	echo "[".$outs[$id]["url_id"]."] '".$urls[$id]."' => [".$outs[$id]["tid"]."] [".$outs[$id]["result"]["info"]["http_code"]."]".
	 	"\n\t'".substr(trim($outs[$id]["result"]["body"]),0,100)."'\n";
}
echo "\n";

foreach($urls as $id=>$dummy) {
	if ($outs[$id]["result"]["error"]) {
		echo "first error: [".$outs[$id]["url_id"]."] '".$urls[$id]."' => [".$outs[$id]["tid"]."] '".$outs[$id]["result"]["error"]."'\n";
		break;
	}
}
