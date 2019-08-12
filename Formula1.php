<?php

class Formula1 {
	public $tree=[];
	public $parse_error='';
	private $dual_hp_ops = ['/','*','%','^'];
	private $dual_lp_ops = ['+','-'];
	private $single_ops = ['+','-'];

	public function __construct($formula, $params=[], $precision=null, $precision_mode=PHP_ROUND_HALF_UP) {
		$this->params = $params;
		$this->precision = $precision;
		$this->precision_mode = $precision_mode;
		$f = $this->replace_parenth($formula, 0);
		if(strrpos($f, ')')>-1) {
			$this->parse_error = 'PARSING ERROR: Too many closing parenthesis !';
			return(false);
		}
		$this->tree = $this->parse($f);
	}

	private function do_single_op($op, $x){
		switch($op){
			case '+': return($x);
			case '-': return(-$x);
		}
	}

	private function do_dual_op($x, $op, $y){
		switch($op){
			case '+': return($x+$y);
			case '-': return($x-$y);
			case '*': return($x*$y);
			case '/': return($x/$y);
			case '%': return($x%$y);
			case '^': return($x**$y);
		}
	}

	private function find_closingp($f, $i0){
		if(substr($f,$i0,1)!='(') return(false);
		$level = 0;
		for($i=$i0+1;$i<strlen($f);$i++) {
			$c = substr($f,$i,1);
			if($c== '(') $level++;
			if($c==')') {
				if($level==0) return($i);
				$level--;
			}
		}
		return(false);
	}

	private function replace_parenth($f, $expr_index){
		$allops = array_merge($this->dual_lp_ops, $this->dual_hp_ops, $this->single_ops);
		$nf='';
		for($i=0;$i<strlen($f);$i++) {
			$c = substr($f,$i,1);
			if($c=='(') {
				$closing_idx = $this->find_closingp($f, $i);
				if($closing_idx===false) {
					$this->parse_error = "PARSING ERROR: missing closing parenthesis !";
					return('');
				}
				$expr = substr($f, $i+1, $closing_idx-$i-1);
				$expr_name = 'expr_'.$expr_index++;
				$nf.=$expr_name;
				$this->params[$expr_name] = $this->replace_parenth($expr, $expr_index);
				$i =  $closing_idx; // let the loop do the +1
			} else {
				$nf .= $c;
			}
		}
		return($nf);
	}


	private function parse($f){	
//echo('<div style="font-size:12px;color:blue;">Parsing:'.$f.'</div>');		
		$re = '/^(.+?)([\\'.implode('\\',$this->dual_lp_ops).'])(.+)$/'; // dual low priority op
		if(preg_match($re, $f, $matches, PREG_OFFSET_CAPTURE, 0)){ 
			$recur = $this->parse($matches[3][0]);
			if($recur!==false) return([$this->parse($matches[1][0]), $matches[2][0], $recur]);
			else return(false);
		} else {
			$re = '/^(.+?)([\\'.implode('\\',$this->dual_hp_ops).'])(.+)$/'; // dual high priority op
			if(preg_match($re, $f, $matches, PREG_OFFSET_CAPTURE, 0)){ 
				$recur = $this->parse($matches[3][0]);
				if($recur!==false) return([$this->parse($matches[1][0]), $matches[2][0], $recur]);
				else return(false);
			} else {
				$re = '/^([\\'.implode('\\',$this->single_ops).'])(.+)$/'; // single op
				if(preg_match($re, $f, $matches, PREG_OFFSET_CAPTURE, 0)){ 
					$recur = $this->parse($matches[2][0]);
					if($recur!==false) return([null, $matches[1][0], $recur]);
					else return(false);
				} else {
					$re1 = '/^([A-Za-z][A-Za-z0-9_]*)\[\"([A-Za-z0-9_]+)\"\]$/'; // arrays with alphanum keys
					$re2 = "/^([A-Za-z][A-Za-z0-9_]*)\[\'([A-Za-z0-9_]+)\'\]$/"; // arrays with alphanum keys
					if(preg_match($re1, $f, $matches1, PREG_OFFSET_CAPTURE, 0)||preg_match($re2, $f, $matches2, PREG_OFFSET_CAPTURE, 0)){ 
						$array_name = (count($matches1)>0) ? $matches1[1][0] : $matches2[1][0];
						$array_key = (count($matches1)>0) ? $matches1[2][0] : $matches2[2][0];
						if(array_key_exists($array_name, $this->params) && is_array($this->params[$array_name])) {
							if(array_key_exists($array_key, $this->params[$array_name])) {
								return($this->parse($this->params[$array_name][$array_key]));
							} else {
								$this->parse_error = 'PARSING ERROR: Unknown key "'.$array_key.'" for array "'.$array_name.'" ';
								return(false);
							}
						} else {
							$this->parse_error = 'PARSING ERROR: Unknown array "'.$array_name.'" ';
							return(false);
						}
					} else {
						$re = '/^([A-Za-z][A-Za-z0-9_]*)\[(\d+)\]$/'; // arrays with alphanum keys
						if(preg_match($re, $f, $matches, PREG_OFFSET_CAPTURE, 0)){ 
							$array_name = $matches[1][0];
							$array_index = $matches[2][0];
							if(array_key_exists($array_name, $this->params) && is_array($this->params[$array_name])) {
								if(isset($this->params[$array_name][$array_index])) {
									return($this->parse($this->params[$array_name][$array_index]));
								} else {
									$this->parse_error = 'PARSING ERROR: Unknown index "'.$array_index.'" for array "'.$array_name.'" ';
									return(false);
								}
							} else {
								$this->parse_error = 'PARSING ERROR: Unknown array "'.$array_name.'" ';
								return(false);
							}
						} else {
							$re = '/^([A-Za-z][A-Za-z0-9_]*)$/'; // simple variables
							if(preg_match($re, $f, $matches, PREG_OFFSET_CAPTURE, 0)){ 
								if(array_key_exists($matches[0][0], $this->params)) return($this->parse($this->params[$matches[0][0]]));
								else {
									$this->parse_error = 'PARSING ERROR: Unknown expression "'.$matches[0][0].'" ';
									return(false);
								}
							} else {				
								$re = '/^\d*(\.\d+)?$/'; // unsigned float or int
								if(preg_match($re, $f, $matches, PREG_OFFSET_CAPTURE, 0)){ 
									return($matches[0][0]);
								} else {
									$this->parse_error = 'PARSING ERROR at '.$f.'<br/>';
									return(false);
								}
							}
						}
					}
				}
			}
		}
	}

	private function comp($t) {
		if(!is_array($t)) return($t);
		if($t[0]===null) { //unary op
			return($this->do_single_op($t[1],$this->comp($t[2])));
		} else {
			return($this->do_dual_op($this->comp($t[0]), $t[1],$this->comp($t[2])));
		}
	}

	public function compute(){
		if($this->precision === null) return($this->comp($this->tree));
		else return(round($this->comp($this->tree),$this->precision, $this->precision_mode));
	}

}
