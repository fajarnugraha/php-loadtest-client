<?php 

namespace MiscHelper;

class Curl {
	private $ch = null;
	private $target = [];
	private $params = [
		'timeout' => [
			'connect' => 10,
			'request' => 30,
		],
		'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 11_1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/87.0.4280.88 Safari/537.36',
		'length' => [ 'sample' => 100 ],
	];
	private $result = [];

	public function __construct($target = []) {
		if (!empty($target)) $this->request($target);
	}

	public function request($target = [], $params = []) {
		if (!empty($target)) {
			if (is_string($target)) $this->target['url'] = $target;
			else $this->target = $target;
		}
		if (!isset($this->target['header'])) $this->target['header'] = [];
		if (!empty($params)) $this->mergeParams($params);
		$this->ch = curl_init();

		curl_setopt($this->ch, CURLOPT_URL, $this->target['url']);
		curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($this->ch, CURLOPT_HEADER, false);
		curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($this->ch, CURLINFO_HEADER_OUT, true);

		curl_setopt($this->ch, CURLOPT_CONNECTTIMEOUT, $this->params['timeout']['connect']);
		curl_setopt($this->ch, CURLOPT_TIMEOUT, $this->params['timeout']['request']);

		if (!empty($this->target['post'])) curl_setopt($this->ch, CURLOPT_POSTFIELDS, $this->target['post']);
		if (empty($this->target['header']['user-agent']) && !empty($this->params['user-agent'])) $this->target['header']['User-Agent'] = $this->params['user-agent'];
		curl_setopt($this->ch, CURLOPT_HTTPHEADER, $this->mergeKeyValue($this->target['header']));

		$output_headers = [];
		curl_setopt($this->ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$output_headers) {return Curl::getOutputHeaders($curl, $header, $output_headers);});

		$body = curl_exec($this->ch);
		$info = curl_getinfo($this->ch);
		if (($body === FALSE) || (!$info["http_code"]) || ($info["http_code"] >= 400)) {
			$error = ''; $errno=curl_errno($this->ch);
			if ($info["http_code"]) $error = 'http code "'.$info["http_code"].'"';
			if ($info["http_code"] && $errno) $error .= ', ';
		    if ($errno) $error .= 'curl error #'.$errno.': '.curl_error($this->ch);
		} else {
			$error = false;
		}
		curl_close($this->ch);

		if (strlen($body) > $this->params['length']['sample']) {
			$body_sample = substr($body, 0, $this->params['length']['sample']);
		} else {
			$body_sample = $body;
		}
		if ($body_sample && preg_match('/[^\x00-\x7E]/', $body_sample)) {
			$body_sample='<binary>';
		} else {
			$body_sample=str_replace("\n", " ", $body_sample);
		}

		$this->result = [
			'body' => $body,
			'length' => strlen($body),
			'sample' => $body_sample,
			'header' => $output_headers,
			'info' => $info,
			'error' => $error,
		];
		return $this->result;
	}

	public function mergeParams($params = []) {
		foreach($params as $key => $val) {
			if (is_array($val)) {
				$this->params[$key] = array_merge($this->params[$key], $val);
			} else {
				$this->params[$key] = $val;
			}
		}
	}

	public function mergeKeyValue($arr = []) {
		$merged = [];
		foreach($arr as $key => $val) $merged[] = $key.": ".$val;
		return $merged;
	}

	public static function getOutputHeaders($curl, $header, &$output_headers) {
	    $len = strlen($header);
	    $header = explode(':', $header, 2);
	    if (count($header) < 2) // ignore invalid headers
	      return $len;
	    $output_headers[strtolower(trim($header[0]))][] = trim($header[1]);
	    return $len;
	}

}

