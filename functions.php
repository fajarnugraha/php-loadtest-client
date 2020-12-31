<?php
require_once(__DIR__."/vendor/autoload.php");
use MiscHelper\Curl;
use MiscHelper\ProgressBar;
use Garden\Cli\Cli;  

function get_args($argc, $argv) {
	$cli = new Cli(); 
	$cli->description('Perform parallel http requests.')
	    ->opt('url:u', 'URL to connect (GET requests only), e.g. "http://localhost:8080/?q=X". Either "url" or "target-file" is required.')
	    ->opt('target-file:f', 'JSON file with URL to connect (support GET/POST and custom headers). Either "url" or "target-file" is required. JSON file content example: {"url":"http:\/\/localhost:8080/?q=X","post":{"a":"1","b":"2"},"header":{"User-Agent":"dummy"}}')
	    ->opt('requests:r', 'Total number of requests.', true, 'integer')
	    ->opt('threads:t', 'Total number of parallel threads.', true, 'integer')
	    ->opt('tag-sequence:s', 'Whether to tag request with additional GET variable "&i=n" where n is test sequence number')
	    ->opt('ramp-up-requests:a', 'Total number of ramp up requests.', false, 'integer')
	    ->opt('ramp-up-threads:m', 'Total number of ramp up parallel threads.', false, 'integer')
	    ; 
	 
	$args = $cli->parse($argv, true);
	$args->setCommand($argv[0]);
	return $args;	
}

function get_base_target($args) {
	$base_url=$args->getOpt('url');
	if (substr($base_url, 0, 4) == "http") {
		$base_target = ["url" => $base_url];
	} else $base_target=json_decode(@file_get_contents($args->getOpt('target-file')), true);
	if (@substr($base_target["url"], 0, 4) != "http") return false;
	return $base_target;
}

function create_targets($base_target, $num, $tag) {
	for ($i = 0; $i < $num; $i++) {
		$targets[$i] = $base_target;
		if ($tag) {
			if (strpos($targets[$i]["url"], "?") === false) $targets[$i]["url"] .= "?";
			else $targets[$i]["url"] .= "&";
			$targets[$i]["url"] .= "i=$i";
		}
	}
	return $targets;	
}

function get_outputs($targets, $cout, $params) {
	$p = new ProgressBar();
	foreach ($targets as $dummy) {
		$data=$cout->pop($params["timeout"]["thread"]);
		if ($data === false) break;
		$outs[$data["url_id"]] = $data;
		if ($data["result"]["error"]) $p->print(ProgressBar::TYPE_ERROR);
		else $p->print(ProgressBar::TYPE_OK);
	}
	$p->end();
	return $outs;
}

function memory_get_usage_mb() {
	return (number_format(memory_get_usage()/(1024**2), 1)."MB");
}

function pretty_number($number,$decimals=3) {
	return number_format($number, $decimals);
}

function get_targets($cin, $cout, $params) {
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
}

function get_ok_index($outs) {
	$i=0; $ok_index=[];
	foreach($outs as $id => $out) {
		if (!$out["result"]["error"]) {
			$ok_index[]=$id;
			$i++;
		}
	}
	sort($ok_index);
	return $ok_index;
}

function get_error_index($outs) {
	$i=0; $error_index=[];
	foreach($outs as $id => $out) {
		if ($out["result"]["error"]) {
			$error_index[]=$id;
			$i++;
		}
	}
	sort($error_index);
	return $error_index;
}

function print_sample_responses($targets, $outs, $ok_index, $params) {
	$ok_max_index=count($ok_index)-1;
	if ($ok_index) $samples_index=[$ok_index[0]];
	if ($ok_max_index) $samples_index[]=$ok_index[$ok_max_index];
	for ($i=0; $i < min(2,$ok_max_index-1); $i++) {
		while (array_search($index=$ok_index[rand(1, $ok_max_index-1)], $samples_index));
		$samples_index[]=$index;
	}
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
}

function print_sample_errors($targets, $outs, $error_index) {
	$samples_index=[$error_index[0]];
	$errors_max_index=count($error_index)-1;
	for ($i=0; $i < min(2,$errors_max_index-1); $i++) {
		while (array_search($index=rand(1, $errors_max_index-1), $samples_index));
		$samples_index[]=$error_index[$index];
	}
	if ($errors_max_index) $samples_index[]=$error_index[$errors_max_index];
	asort($samples_index);
	foreach($samples_index as $dummy=>$id) {
		echo "[url #".$id."] [".$targets[$id]["url"]."] => [".$outs[$id]["result"]["error"]."]\n";
	}
}
