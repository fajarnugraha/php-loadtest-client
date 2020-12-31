<?php 

namespace MiscHelper;

class ProgressBar {
	private $style;
	public const STYLE_SIMPLE = 0;
	public const STYLE_ANSI = 1;
	public const TYPE_ERROR = 0;
	public const TYPE_OK = 1;
	private $params = [
		'max' => ['column' => 100],
	];
	private $counter = [
		'total' => 0,
		'item' => 0,
		'group' => 0,
	];
	private $flag = [
		'error' => 0,
	];
	private $label = [
		'item' => [ 'error' => '!', 'ok' => '.'],
		'group' => [ 'error' => '!', 'ok' => '=', 'partial_error' => 'x', 'partial_ok' => '-'],
	];

	public function __construct($style = ProgressBar::STYLE_ANSI) {
		$this->style = $style;
	}

	public function print($type = ProgressBar::TYPE_OK) {
		if ($this->counter['total'] == 0) {
			echo "#".number_format($this->counter['total'])."\n";
		}
		if ($type == ProgressBar::TYPE_ERROR) {
			echo $this->label['item']['error'];
			$this->flag['error'] = true;
		} else {
			echo $this->label['item']['ok'];
		}
		$this->counter['item']++;
		if (++$this->counter['total'] % $this->params['max']['column'] === 0) {
			echo "\r";
			for ($i=0; $i<$this->params['max']['column']; $i++) {
				echo " ";
			}
			if ($this->counter['group'] == $this->params['max']['column']) {
				echo "\r";
				$this->counter['group']=0;
			} else {
				echo "\033[F";
			}
			if ($this->counter['group']) echo "\033[".$this->counter['group']."C";
			if ($this->flag['error']) echo $this->label['group']['error'];
			else echo $this->label['group']['ok'];
			echo " #".number_format($this->counter['total'])."\n";
			$this->flag['error']=false; $this->counter['item']=0; $this->counter['group']++;
		}
	}

	public function end() {
		if ($this->counter['total'] % $this->params['max']['column']) {
			echo "\r";
			for ($i=0; $i<$this->params['max']['column']; $i++) {
				echo " ";
			}
			if ($this->counter['group'] == $this->params['max']['column']) {
				echo "\r";
				$this->counter['group']=0;
			} else {
				echo "\033[F";
			}
			if ($this->counter['group']) echo "\033[".$this->counter['group']."C";
			if ($this->flag['error']) echo $this->label['group']['partial_error'];
			else echo $this->label['group']['partial_ok'];
			echo " #".number_format($this->counter['total'])."\n";
			$this->flag['error']=false; $this->counter['item']=0; $this->counter['group']++;
		}
	}

}

